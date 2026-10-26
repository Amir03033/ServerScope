<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional Idempotency-Key support for API writes: a retried request
 * with the same key (per token) returns the stored response instead of
 * being executed again for 24 hours. Reusing a key for a different
 * request is refused with 422. Requests without the header are not
 * affected.
 */
class IdempotentRequests
{
    public const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }

        if (! preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $key)) {
            return response()->json(['message' => 'Ongeldige Idempotency-Key (8-128 tekens: letters, cijfers, . _ : -).'], 422);
        }

        $token = $request->attributes->get('apiToken');
        $cacheKey = 'idempotency:'.hash('sha256', ($token?->id ?? 'anon').'|'.$key);
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            if ($stored['fingerprint'] !== $fingerprint) {
                return response()->json(['message' => 'Deze Idempotency-Key is al gebruikt voor een ander verzoek.'], 422);
            }

            return response($stored['body'], $stored['status'], [...$stored['headers'], 'Idempotent-Replayed' => 'true']);
        }

        // Concurrent duplicates: only one may execute.
        $lock = Cache::lock($cacheKey.':lock', 30);

        if (! $lock->get()) {
            return response()->json(['message' => 'Een verzoek met deze Idempotency-Key wordt al verwerkt.'], 409);
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => ['Content-Type' => $response->headers->get('Content-Type', 'application/json')],
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
