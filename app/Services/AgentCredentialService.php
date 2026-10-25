<?php

namespace App\Services;

use App\Models\AgentCredential;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Issues, rotates and revokes agent credentials.
 *
 * Tokens are 32 random bytes (64 hex characters). Only their SHA-256 hash
 * is stored; the plaintext token is returned once to the caller.
 */
class AgentCredentialService
{
    /**
     * @return array{credential: AgentCredential, token: string}
     */
    public function issue(Server $server, string $name): array
    {
        $token = bin2hex(random_bytes(32));

        $credential = $server->agentCredentials()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
        ]);

        return ['credential' => $credential, 'token' => $token];
    }

    /**
     * Revoke every active credential of the server and issue a new one.
     *
     * @return array{credential: AgentCredential, token: string, revoked: int}
     */
    public function rotate(Server $server, string $name): array
    {
        return DB::transaction(function () use ($server, $name): array {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            $revoked = $this->revokeAll($server);

            return [...$this->issue($server, $name), 'revoked' => $revoked];
        });
    }

    public function revoke(AgentCredential $credential): void
    {
        if ($credential->revoked_at === null) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAll(Server $server): int
    {
        return $server->agentCredentials()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
