<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language: the user's preference, otherwise the
 * visitor's choice in the session, on public status pages the browser
 * language, otherwise the application default (English).
 */
class SetLocale
{
    /** Public pages for visitors without an account: follow the browser. */
    private const BROWSER_LANGUAGE_ROUTES = ['status.show', 'status.json'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get(Locale::SESSION_KEY) : null)
            ?? ($request->routeIs(...self::BROWSER_LANGUAGE_ROUTES) ? Locale::fromBrowser($request) : null)
            ?? config('app.locale');

        $locale = Locale::normalize($locale);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
