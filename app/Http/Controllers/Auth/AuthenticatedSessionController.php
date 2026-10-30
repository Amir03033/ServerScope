<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $key = Str::transliterate($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.messages.throttled'),
            ]);
        }

        $user = \App\Models\User::query()->where('email', $email)->first();

        if ($user === null || ! Auth::getProvider()->validateCredentials($user, ['password' => $credentials['password']])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => __('auth.messages.invalid_credentials'),
            ]);
        }

        RateLimiter::clear($key);
        Auth::getProvider()->rehashPasswordIfRequired($user, ['password' => $credentials['password']]);

        // Second factor: remember who passed the password step, but do not
        // log in until the code is verified.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
