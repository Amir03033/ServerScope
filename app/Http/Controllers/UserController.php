<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Members of the current team: all members see the team's monitoring
 * data; owners and administrators manage configuration. New users
 * receive a password reset link instead of a password chosen by an
 * administrator. Roles live on the membership (team_user.role).
 */
class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        return view('users.index', [
            'users' => $this->members()->orderBy('name')->get(),
            'invitations' => \App\Models\TeamInvitation::query()->where('team_id', Tenant::id())->whereNull('accepted_at')->where('expires_at', '>', now())->orderBy('expires_at')->get(),
        ]);
    }

    /**
     * Invite by e-mail. The answer is the same for an address with and
     * without an account; the person accepts through the link in the mail.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'is_admin' => ['boolean'],
        ]);

        $email = strtolower(trim($data['email']));

        // Members of the own team are visible to its administrators anyway.
        if ($this->members()->where('users.email', $email)->exists()) {
            return back()->withErrors(['email' => __('admin.users.flash.already_member')])->withInput();
        }

        $role = $request->boolean('is_admin') ? 'admin' : 'member';
        $team = \App\Models\Team::query()->findOrFail(Tenant::id());
        [$invitation, $plain] = \App\Models\TeamInvitation::issue($team, $email, $role, $data['name'] ?? null, $request->user()->id);
        $this->mailInvitation($team, $email, $plain);

        Audit::log('user.invited', "Uitnodiging verstuurd aan {$email}".($role === 'admin' ? ' (beheerder)' : ''), $team);

        return back()->with('success', __('admin.users.flash.invited', ['email' => $email]));
    }

    /** Send the invitation again with a new link; the old link stops working. */
    public function resendInvitation(Request $request, \App\Models\TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('admin');
        $this->ownInvitation($invitation);

        $team = \App\Models\Team::query()->findOrFail(Tenant::id());
        [, $plain] = \App\Models\TeamInvitation::issue($team, $invitation->email, $invitation->role, $invitation->name, $request->user()->id);
        $this->mailInvitation($team, $invitation->email, $plain);
        Audit::log('user.invitation_resent', "Uitnodiging opnieuw verstuurd aan {$invitation->email}", $team);

        return back()->with('success', __('admin.users.flash.invitation_resent', ['email' => $invitation->email]));
    }

    public function revokeInvitation(\App\Models\TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('admin');
        $this->ownInvitation($invitation);

        $invitation->delete();
        Audit::log('user.invitation_revoked', "Uitnodiging voor {$invitation->email} ingetrokken", \App\Models\Team::query()->findOrFail(Tenant::id()));

        return back()->with('success', __('admin.users.flash.invitation_revoked', ['email' => $invitation->email]));
    }

    /** An invitation of another team (or an used one) does not exist for this team. */
    private function ownInvitation(\App\Models\TeamInvitation $invitation): void
    {
        abort_unless($invitation->team_id === Tenant::id() && $invitation->accepted_at === null, 404);
    }

    private function mailInvitation(\App\Models\Team $team, string $email, string $plain): void
    {
        \Illuminate\Support\Facades\Notification::route('mail', $email)
            ->notify(new \App\Notifications\TeamInvitationNotice($team->name, route('invitations.show', $plain)));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');
        $role = $this->roleOrFail($user);

        $makeAdmin = $request->boolean('is_admin');

        if (! $makeAdmin && $user->is($request->user())) {
            return back()->with('error', __('admin.users.flash.own_admin'));
        }

        if ($role === 'owner' && $request->user()->roleIn(Tenant::id()) !== 'owner') {
            return back()->with('error', __('admin.users.flash.owner_only'));
        }

        if (! $makeAdmin && in_array($role, ['owner', 'admin'], true) && $this->isLastAdmin($user)) {
            return back()->with('error', __('admin.users.flash.last_admin'));
        }

        if (! $makeAdmin && $role === 'owner' && $this->isLastOwner($user)) {
            return back()->with('error', __('admin.users.flash.needs_owner'));
        }

        $newRole = $makeAdmin ? ($role === 'owner' ? 'owner' : 'admin') : 'member';
        $user->teams()->updateExistingPivot(Tenant::id(), ['role' => $newRole]);
        $user->syncAdminMirror();
        Audit::log('user.role_changed', "{$user->email} is nu ".($makeAdmin ? 'beheerder' : 'gebruiker'), $user);

        return back()->with('success', __('admin.users.flash.role_updated'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');
        $role = $this->roleOrFail($user);

        if ($user->is($request->user())) {
            return back()->with('error', __('admin.users.flash.own_account'));
        }

        if (in_array($role, ['owner', 'admin'], true) && $this->isLastAdmin($user)) {
            return back()->with('error', __('admin.users.flash.last_admin_delete'));
        }

        if ($role === 'owner' && ($request->user()->roleIn(Tenant::id()) !== 'owner' || $this->isLastOwner($user))) {
            return back()->with('error', __('admin.users.flash.owner_delete'));
        }

        $email = $user->email;

        // Accounts that also belong to other teams only leave this team.
        if ($user->teams()->where('teams.id', '!=', Tenant::id())->exists()) {
            $user->teams()->detach(Tenant::id());

            if ($user->current_team_id === Tenant::id()) {
                $user->forceFill(['current_team_id' => null])->saveQuietly();
            }

            Audit::log('user.removed_from_team', "Gebruiker {$email} uit het team verwijderd");

            return back()->with('success', __('admin.users.flash.removed_from_team', ['email' => $email]));
        }

        $user->delete();
        Audit::log('user.deleted', "Gebruiker {$email} verwijderd");

        return back()->with('success', __('admin.users.flash.deleted', ['email' => $email]));
    }

    /** Members of the current team with their role. */
    private function members()
    {
        return User::query()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->where('team_user.team_id', Tenant::id())
            ->select('users.*', 'team_user.role as team_role');
    }

    /** The user's role in the current team; 404 for anyone outside it. */
    private function roleOrFail(User $user): string
    {
        return $user->roleIn(Tenant::id()) ?? abort(404);
    }

    private function isLastAdmin(User $user): bool
    {
        return $this->members()->whereIn('team_user.role', ['owner', 'admin'])->where('users.id', '!=', $user->id)->doesntExist();
    }

    private function isLastOwner(User $user): bool
    {
        return $this->members()->where('team_user.role', 'owner')->where('users.id', '!=', $user->id)->doesntExist();
    }
}
