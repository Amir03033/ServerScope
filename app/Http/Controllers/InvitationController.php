<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accepting a team invitation. Whoever holds the link owns the mailbox,
 * so only here is it shown whether the address already has an account.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = TeamInvitation::findValid($token);

        if ($invitation === null) {
            return view('invitations.invalid');
        }

        $account = User::query()->where('email', $invitation->email)->first();
        $user = $request->user();

        return view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'hasAccount' => $account !== null,
            'signedInAs' => $user,
            'matches' => $user !== null && $user->email === $invitation->email,
        ]);
    }

    /** An existing account joins; it must be signed in as that address. */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::findValid($token);
        $user = $request->user();

        if ($invitation === null || $user === null || $user->email !== $invitation->email || ! $user->hasVerifiedEmail()) {
            abort(404);
        }

        DB::transaction(function () use ($invitation, $user): void {
            if (! $user->belongsToTeam($invitation->team_id)) {
                $user->teams()->attach($invitation->team_id, ['role' => $invitation->role]);
            }
            $invitation->update(['accepted_at' => now()]);
            Tenant::run($invitation->team_id, fn () => Audit::log('user.joined_team', "{$user->email} heeft de uitnodiging geaccepteerd", $user));
        });

        return redirect()->route('dashboard')->with('success', __('invitations.accepted', ['team' => $invitation->team->name]));
    }

    /** A new person: create the account, verified by the mailbox. */
    public function register(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::findValid($token);

        if ($invitation === null || User::query()->where('email', $invitation->email)->exists()) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($invitation, $data): User {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $invitation->email;
            $user->password = Hash::make($data['password']);
            $user->email_verified_at = now();
            $user->is_admin = in_array($invitation->role, ['owner', 'admin'], true);
            $user->current_team_id = $invitation->team_id;
            $user->locale = app()->getLocale();
            $user->save();
            $user->teams()->attach($invitation->team_id, ['role' => $invitation->role]);
            $invitation->update(['accepted_at' => now()]);
            Tenant::run($invitation->team_id, fn () => Audit::log('user.joined_team', "{$user->email} heeft de uitnodiging geaccepteerd", $user));

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
