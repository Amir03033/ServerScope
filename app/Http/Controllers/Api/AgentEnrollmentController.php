<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\Hostname;
use App\Services\AgentCredentialService;
use App\Services\EnrollmentService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agent-side endpoints used by install.sh. The existing metrics endpoint
 * (POST /api/agent/metrics) is untouched.
 */
class AgentEnrollmentController extends Controller
{
    /** The same answer for every token that cannot be used. */
    private const NEUTRAL = 'Ongeldig of verlopen koppeltoken.';

    public function enroll(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        // The token is not validated with rules: its validity must never be
        // distinguishable from the outside (and it must not be echoed).
        $facts = $request->validate([
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            'os' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._()\/+-]+\z/'],
            'arch' => ['required', Rule::in(['amd64', 'arm64'])],
            'agent_version' => ['required', 'string', 'max:20', 'regex:/\A\d+\.\d+\.\d+\z/'],
        ]);

        $token = $request->input('token');
        try {
            $result = is_string($token) ? $enrollment->redeem($token, [
                'hostname' => strtolower(rtrim(trim($facts['hostname']), '.')),
                'os' => trim($facts['os']),
                'arch' => $facts['arch'],
                'agent_version' => $facts['agent_version'],
            ], $request->ip()) : null;
        } catch (\App\Support\PlanLimitException $e) {
            // The token stays usable (the claim was rolled back).
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if ($result === null) {
            return response()->json(['message' => self::NEUTRAL], 401);
        }

        return response()->json(['data' => [
            'server_id' => $result['server']->id,
            'server_name' => $result['server']->name,
            'agent_token' => $result['agent_token'],
        ]], 201)->header('Cache-Control', 'no-store');
    }

    /** What install.sh needs to confirm the first sample arrived. */
    public function self(Request $request): JsonResponse
    {
        $server = $request->attributes->get('agentServer');

        return response()->json(['data' => [
            'server_id' => $server->id,
            'server_name' => $server->name,
            'status' => $server->displayStatus(),
            'last_seen_at' => $server->last_seen_at?->toIso8601String(),
            // Added in phase 3 (the updater confirms the new version runs).
            'agent_version' => $server->agent_version,
        ]]);
    }

    /**
     * Revoke the calling credential (install.sh --uninstall). A credential
     * can only revoke itself, never other credentials of the server.
     */
    public function revoke(Request $request, AgentCredentialService $credentials): JsonResponse
    {
        $credential = $request->attributes->get('agentCredential');
        $credentials->revoke($credential);

        Audit::log('agent_credential.self_revoked', "Agentcredential '{$credential->name}' ingetrokken door de agent (verwijderd met het installatiescript)", $request->attributes->get('agentServer'), [
            'credential_id' => $credential->id,
        ]);

        return response()->json(['message' => 'Credential ingetrokken.']);
    }
}
