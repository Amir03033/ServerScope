<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    // ---- Profile: set up / disable ----

    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null])->save();
        }

        return redirect()->route('profile.edit')->with('two_factor_setup', true);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        if (! $this->verifyCode($user, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'De code is ongeldig. Controleer de tijd op je telefoon en probeer het opnieuw.'])->errorBag('twoFactor');
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $user->regenerateRecoveryCodes();
        Audit::log('user.two_factor_enabled', 'Tweestapsverificatie ingeschakeld', $user);

        return redirect()->route('profile.edit')
            ->with('success', 'Tweestapsverificatie is ingeschakeld.')
            ->with('recovery_codes', $codes);
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactor', ['current_password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactorEnabled(), 404);

        $codes = $request->user()->regenerateRecoveryCodes();
        Audit::log('user.recovery_codes_regenerated', 'Herstelcodes vernieuwd', $request->user());

        return redirect()->route('profile.edit')->with('success', 'Nieuwe herstelcodes gegenereerd; de oude zijn ongeldig.')->with('recovery_codes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactor', ['current_password' => ['required', 'current_password']]);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Audit::log('user.two_factor_disabled', 'Tweestapsverificatie uitgeschakeld', $request->user());

        return redirect()->route('profile.edit')->with('success', 'Tweestapsverificatie is uitgeschakeld.');
    }

    // ---- Login challenge ----

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('login.id');
        $user = $userId ? User::query()->find($userId) : null;

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login');
        }

        $key = 'two-factor:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Te veel pogingen. Probeer het over '.RateLimiter::availableIn($key).' seconden opnieuw.']);
        }

        $valid = $request->filled('recovery_code')
            ? $user->useRecoveryCode((string) $request->input('recovery_code'))
            : $this->verifyCode($user, (string) $request->input('code'));

        if (! $valid) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['code' => 'De code is ongeldig.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if ($request->filled('recovery_code')) {
            Audit::log('user.recovery_code_used', 'Ingelogd met een herstelcode', $user);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.id', 'login.remember']);

        return redirect()->route('login');
    }

    /**
     * Verify a TOTP code and reject reuse of the same time step.
     */
    private function verifyCode(User $user, string $code): bool
    {
        $step = Totp::verify((string) $user->two_factor_secret, $code);

        if ($step === null) {
            return false;
        }

        return Cache::add("two-factor-used:{$user->id}:{$step}", true, now()->addMinutes(3));
    }
}
