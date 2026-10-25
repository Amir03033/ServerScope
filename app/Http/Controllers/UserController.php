<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Single-tenant user management: all users see all monitoring data;
 * administrators manage configuration. New users receive a password
 * reset link instead of a password chosen by an administrator.
 */
class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        return view('users.index', ['users' => User::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'is_admin' => ['boolean'],
        ]);

        $user = new User;
        $user->name = $validated['name'];
        $user->email = strtolower(trim($validated['email']));
        // Unusable random password until the user sets their own.
        $user->password = Hash::make(Str::random(64));
        $user->is_admin = $request->boolean('is_admin');
        $user->save();

        Password::sendResetLink(['email' => $user->email]);
        Audit::log('user.invited', "Gebruiker {$user->email} uitgenodigd".($user->is_admin ? ' als beheerder' : ''), $user);

        return back()->with('success', "Uitnodiging verstuurd naar {$user->email}. De gebruiker stelt zelf een wachtwoord in via de link (60 minuten geldig).");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');

        $makeAdmin = $request->boolean('is_admin');

        if (! $makeAdmin && $user->is_admin && $this->isLastAdmin($user)) {
            return back()->with('error', 'Er moet minstens één beheerder overblijven.');
        }

        if (! $makeAdmin && $user->is($request->user())) {
            return back()->with('error', 'Je kunt je eigen beheerdersrechten niet intrekken.');
        }

        $user->is_admin = $makeAdmin;
        $user->save();
        Audit::log('user.role_changed', "{$user->email} is nu ".($makeAdmin ? 'beheerder' : 'gebruiker'), $user);

        return back()->with('success', 'Rol bijgewerkt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');

        if ($user->is($request->user())) {
            return back()->with('error', 'Je kunt je eigen account hier niet verwijderen.');
        }

        if ($user->is_admin && $this->isLastAdmin($user)) {
            return back()->with('error', 'De laatste beheerder kan niet worden verwijderd.');
        }

        $email = $user->email;
        $user->delete();
        Audit::log('user.deleted', "Gebruiker {$email} verwijderd");

        return back()->with('success', "Gebruiker {$email} verwijderd. Incidenten en auditregels blijven bewaard.");
    }

    private function isLastAdmin(User $user): bool
    {
        return User::query()->where('is_admin', true)->whereKeyNot($user->id)->doesntExist();
    }
}
