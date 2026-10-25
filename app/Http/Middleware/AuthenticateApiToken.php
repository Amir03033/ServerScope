<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication with personal API tokens ("ssk_..."). Optional
 * parameter: the required ability, e.g. api.token:write.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || ! preg_match('/\Assk_[a-f0-9]{48}\z/', $plain)) {
            return $this->error('Ontbrekend of ongeldig API-token.', 401);
        }

        $token = ApiToken::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();

        if ($token === null || ! $token->isActive() || $token->user === null) {
            return $this->error('Ontbrekend of ongeldig API-token.', 401);
        }

        if (! $token->can($ability)) {
            return $this->error("Dit token mist de bevoegdheid '{$ability}'.", 403);
        }

        // Avoid a write per request: update at most once a minute.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('apiToken', $token);

        return $next($request);
    }

    private function error(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status, ['WWW-Authenticate' => 'Bearer']);
    }
}
