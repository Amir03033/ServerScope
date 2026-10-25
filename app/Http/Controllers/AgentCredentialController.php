<?php

namespace App\Http\Controllers;

use App\Models\AgentCredential;
use App\Models\Server;
use App\Services\AgentCredentialService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AgentCredentialController extends Controller
{
    public function __construct(
        private AgentCredentialService $credentials,
    ) {}

    /**
     * Issue an additional credential without revoking existing ones.
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $issued = $this->credentials->issue($server, $validated['name']);

        Audit::log('agent_credential.issued', "Agentcredential '{$issued['credential']->name}' aangemaakt voor {$server->name}", $server, [
            'credential_id' => $issued['credential']->id,
        ]);

        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Nieuwe agentcredential aangemaakt.')
            ->with('agent_token', $issued['token']);
    }

    /**
     * Revoke all active credentials and issue a replacement.
     */
    public function rotate(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->credentials->rotate($server, $validated['name'] ?? 'Primary agent');

        Audit::log('agent_credential.rotated', "Agentcredentials geroteerd voor {$server->name}", $server, [
            'revoked' => $result['revoked'],
            'credential_id' => $result['credential']->id,
        ]);

        return redirect()
            ->route('servers.show', $server)
            ->with('success', "Credentials geroteerd: {$result['revoked']} oude credential(s) ingetrokken. Werk de agentconfiguratie bij met het nieuwe token.")
            ->with('agent_token', $result['token']);
    }

    public function destroy(Server $server, AgentCredential $agentCredential): RedirectResponse
    {
        $credential = $agentCredential;
        Gate::authorize('admin');

        $this->credentials->revoke($credential);

        Audit::log('agent_credential.revoked', "Agentcredential '{$credential->name}' ingetrokken voor {$server->name}", $server, [
            'credential_id' => $credential->id,
        ]);

        return redirect()
            ->route('servers.show', $server)
            ->with('success', "Credential '{$credential->name}' is ingetrokken.");
    }
}
