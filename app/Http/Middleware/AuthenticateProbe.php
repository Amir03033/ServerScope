<?php

namespace App\Http\Middleware;

use App\Models\Probe;
use App\Services\Probes\ProbeSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a probe request: known active probe key, timestamp within
 * ProbeSignature::MAX_SKEW_SECONDS, unused nonce and a valid HMAC over
 * method, path, timestamp, nonce and body. Every failure is the same 401.
 * Runs without a tenant: a probe serves all teams, like the main monitor.
 */
class AuthenticateProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Probe-Key');
        $timestamp = (string) $request->header('X-Probe-Timestamp');
        $nonce = (string) $request->header('X-Probe-Nonce');
        $signature = (string) $request->header('X-Probe-Signature');

        if (! preg_match('/\A[a-f0-9]{32}\z/', $key) || ! ctype_digit($timestamp) || ! preg_match(ProbeSignature::NONCE_PATTERN, $nonce) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return $this->reject();
        }

        if (abs(time() - (int) $timestamp) > ProbeSignature::MAX_SKEW_SECONDS) {
            return $this->reject();
        }

        $probe = Probe::query()->where('key', $key)->where('is_active', true)->first();

        if ($probe === null) {
            return $this->reject();
        }

        $expected = ProbeSignature::sign($probe->secret, $request->method(), $request->path(), (int) $timestamp, $nonce, $request->getContent());

        if (! hash_equals($expected, $signature)) {
            return $this->reject();
        }

        // A nonce is accepted once (kept longer than the allowed skew).
        if (! Cache::add("probe-nonce:{$probe->id}:{$nonce}", 1, ProbeSignature::MAX_SKEW_SECONDS * 2 + 60)) {
            return $this->reject();
        }

        $probe->forceFill(['last_seen_at' => now(), 'version' => mb_substr((string) $request->header('X-Probe-Version', ''), 0, 50) ?: $probe->version])->save();
        $request->attributes->set('probe', $probe);

        return $next($request);
    }

    private function reject(): JsonResponse
    {
        return response()->json(['message' => 'Ongeldige of verlopen probe-aanvraag.'], 401);
    }
}
