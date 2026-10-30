<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quick language switch (user menu, guest pages, public status pages).
 * Guests keep the choice in the session; signed-in users also in
 * users.locale. Returns to the previous page, but only on this site.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::SUPPORTED)],
        ]);

        $request->session()->put(Locale::SESSION_KEY, $validated['locale']);
        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        return redirect()->to($this->safePrevious($request));
    }

    /** The previous URL as a path on this site; anything else goes home. */
    private function safePrevious(Request $request): string
    {
        $fallback = $request->user() ? route('dashboard', absolute: false) : route('login', absolute: false);
        $previous = (string) ($request->headers->get('referer') ?: $request->session()->previousUrl());

        if ($previous === '') {
            return $fallback;
        }

        $parts = parse_url($previous);

        if ($parts === false) {
            return $fallback;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return $fallback;
            }

            // Same origin only: scheme, host and port of this request.
            $defaultPort = strtolower($parts['scheme']) === 'https' ? 443 : 80;
            $origin = strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) && (int) $parts['port'] !== $defaultPort ? ':'.$parts['port'] : ''));

            if ($origin !== strtolower($request->getSchemeAndHttpHost())) {
                return $fallback;
            }
        } elseif (! str_starts_with($previous, '/')) {
            return $fallback;
        }

        // Always a path on this site ("//host" would leave it again).
        $path = '/'.ltrim(str_replace('\\', '/', $parts['path'] ?? '/'), '/');

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
