<?php

namespace App\Http\Middleware;

use App\Models\AgentCredential;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $token = $request->bearerToken();

        // Agent tokens are 32 random bytes encoded as 64 hex characters.
        if (
            ! is_string($token)
            || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
        ) {
            return $this->unauthorized();
        }

        $credential = AgentCredential::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        // A soft-deleted server's credentials no longer authenticate.
        if (! $credential || $credential->server === null) {
            return $this->unauthorized();
        }

        $credential->forceFill([
            'last_used_at' => now(),
        ])->save();

        // Make the authenticated credential and its server available
        // to the controller without trusting client-supplied server IDs.
        $request->attributes->set('agentCredential', $credential);
        $request->attributes->set('agentServer', $credential->server);

        // The server, derived from the credential, determines the team.
        return Tenant::run($credential->server->team_id, fn () => $next($request));
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'message' => 'Ongeldige of niet meer geldige agentcredential.',
        ], 401);
    }
}
