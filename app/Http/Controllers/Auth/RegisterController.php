<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Public sign-up: a new account that owns a new, empty team. Disabled
 * unless serverscope.tenancy.registration_enabled is set; the routes
 * then do not exist (404).
 */
class RegisterController extends Controller
{
    public function create(): View
    {
        $this->ensureEnabled();

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'team' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $team = Team::query()->create(['name' => $validated['team']]);

            $user = new User;
            $user->name = $validated['name'];
            $user->email = strtolower(trim($validated['email']));
            $user->password = Hash::make($validated['password']);
            $user->is_admin = true;
            $user->current_team_id = $team->id;
            $user->save();
            $user->teams()->attach($team->id, ['role' => 'owner']);

            Tenant::run($team, fn () => Audit::log('team.registered', "Team {$team->name} geregistreerd door {$user->email}", $user));

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('serverscope.tenancy.registration_enabled'), 404);
    }
}
