<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        Password::sendResetLink(['email' => Str::lower(trim($request->input('email')))]);

        // Same answer whether or not the account exists (no enumeration).
        return back()->with('status', 'Als dit e-mailadres bij een account hoort, is er een resetlink verstuurd. De link is 60 minuten geldig.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'), 'email' => Str::lower(trim($request->input('email')))],
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                Audit::log('user.password_reset', 'Wachtwoord opnieuw ingesteld via resetlink', $user);
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Je wachtwoord is opnieuw ingesteld. Je kunt nu inloggen.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'Deze resetlink is ongeldig of verlopen.']);
    }
}
