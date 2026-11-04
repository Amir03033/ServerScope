<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo is read-only. For a demo user every request that would change
 * something (POST, PUT, PATCH, DELETE, also Livewire and fetch calls) is
 * refused, except signing out and the language switch; pages about money,
 * people, the platform and secrets are not shown. Demo pages are never
 * indexed.
 */
class DemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Demo::isDemoUser($request->user())) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if (! $request->isMethodSafe() && ! in_array($route, Demo::ALLOWED_WRITES, true)) {
            return $this->refuse($request);
        }

        if ($route !== null && $request->routeIs(...Demo::BLOCKED_ROUTES)) {
            return $this->refuse($request);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function refuse(Request $request): Response
    {
        $message = __('demo.unavailable');

        if ($request->expectsJson() || $request->hasHeader('X-Livewire') || ! $request->isMethodSafe() && $request->ajax()) {
            return response()->json(['message' => $message], 403, ['X-Robots-Tag' => 'noindex, nofollow']);
        }

        $back = $request->isMethodSafe() ? route('dashboard') : (url()->previous() ?: route('dashboard'));

        return redirect()->to($back)->with('error', $message)->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }
}
