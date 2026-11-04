<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Demo;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The public demo: /demo signs the visitor in as the demo user of the demo
 * team in their language, without a password; /demo/leave signs out again
 * (optionally on to registration). Only when SERVERSCOPE_DEMO_ENABLED.
 */
class DemoController extends Controller
{
    public function enter(Request $request): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        $current = $request->user();

        // A real account is never swapped for the demo behind its back.
        if ($current !== null && ! Demo::isDemoUser($current)) {
            return redirect()->route('dashboard')->with('error', __('demo.signed_in'));
        }

        $locale = $this->locale($request);
        $user = User::query()->where('is_demo', true)->where('email', Demo::email($locale))->first()
            ?? User::query()->where('is_demo', true)->first();

        abort_if($user === null || $user->current_team_id === null, 404);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(Locale::SESSION_KEY, $locale);

        return redirect()->to($this->next($request))
            ->withCookie(cookie(Demo::COOKIE, '1', 60 * 24))
            ->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function leave(Request $request): RedirectResponse
    {
        if (Demo::isDemoUser($request->user())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $to = $request->query('to') === 'register' ? redirect()->route('register') : redirect()->route('login');

        return $to->withCookie(cookie()->forget(Demo::COOKIE));
    }

    /** Where to go after entering: the page asked for (a path on this site), otherwise the dashboard. */
    private function next(Request $request): string
    {
        $next = (string) $request->query('next', '');

        return preg_match('#^/(?!/)[A-Za-z0-9/_.\-]*$#', $next) === 1 && ! str_starts_with($next, '/demo') ? url($next) : route('dashboard');
    }

    /** The visitor's language: a choice made earlier, otherwise the browser's. */
    private function locale(Request $request): string
    {
        $chosen = $request->session()->get(Locale::SESSION_KEY);

        if (in_array($chosen, Demo::LOCALES, true)) {
            return $chosen;
        }

        return $request->getPreferredLanguage(Demo::LOCALES) ?? 'en';
    }
}
