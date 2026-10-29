<?php

namespace App\Services;

use App\Models\AgentCredential;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ServerRegistrationService
{
    public function __construct(
        private AgentCredentialService $credentials,
    ) {}

    /**
     * Register a server and issue its first agent credential.
     *
     * The plaintext token is returned only once.
     *
     * @return array{server: Server, token: string}
     */
    public function register(
        array $serverData,
        string $credentialName = 'Primary agent',
    ): array {
        if (
            ! isset($serverData['name'], $serverData['hostname'])
            || trim($serverData['name']) === ''
            || trim($serverData['hostname']) === ''
        ) {
            throw new RuntimeException(
                'A server name and hostname are required.'
            );
        }

        return DB::transaction(function () use (
            $serverData,
            $credentialName,
        ): array {
            $server = Server::create([
                'name' => trim($serverData['name']),
                'hostname' => trim($serverData['hostname']),
                'ip_address' => $serverData['ip_address'] ?? null,
                'operating_system' => $serverData['operating_system'] ?? null,
                'agent_version' => $serverData['agent_version'] ?? null,
                'environment' => $serverData['environment'] ?? 'production',
                'notes' => $serverData['notes'] ?? null,
                'status' => 'pending',
            ]);

            $issued = $this->credentials->issue($server, $credentialName);

            return [
                'server' => $server,
                'token' => $issued['token'],
            ];
        });
    }
}
