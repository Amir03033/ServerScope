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

        return view('users.index', ['users' => $this->members()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $email = strtolower(trim((string) $request->input('email')));
        $existing = config('serverscope.tenancy.invitations_enabled') ? User::query()->where('email', $email)->first() : null;

        if ($existing !== null) {
            return $this->addExisting($request, $existing);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'is_admin' => ['boolean'],
        ]);

        $role = $request->boolean('is_admin') ? 'admin' : 'member';

        $user = DB::transaction(function () use ($validated, $role): User {
            $user = new User;
            $user->name = $validated['name'];
            $user->email = strtolower(trim($validated['email']));
            // Unusable random password until the user sets their own.
            $user->password = Hash::make(Str::random(64));
            $user->is_admin = $role === 'admin';
            $user->current_team_id = Tenant::id();
            $user->save();
            $user->teams()->attach(Tenant::id(), ['role' => $role]);

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);
        Audit::log('user.invited', "Gebruiker {$user->email} uitgenodigd".($role === 'admin' ? ' als beheerder' : ''), $user);

        return back()->with('success', __('admin.users.flash.invited', ['email' => $user->email]));
    }

    /** Team invitation of an existing account (only when enabled). */
    private function addExisting(Request $request, User $user): RedirectResponse
    {
        $request->validate(['is_admin' => ['boolean']]);

        if ($user->belongsToTeam(Tenant::id())) {
            return back()->withErrors(['email' => __('admin.users.flash.already_member')]);
        }

        $role = $request->boolean('is_admin') ? 'admin' : 'member';
        $user->teams()->attach(Tenant::id(), ['role' => $role]);
        Audit::log('user.added_to_team', "Bestaand account {$user->email} toegevoegd aan het team", $user);

        return back()->with('success', __('admin.users.flash.added', ['email' => $user->email]));
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
