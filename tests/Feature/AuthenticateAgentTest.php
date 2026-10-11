<?php

namespace Tests\Feature;

use App\Models\AgentCredential;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticateAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_authenticates_the_agent(): void
    {
        [$server, $token, $credential] = $this->createCredential();

        $this->withToken($token)
            ->getJson(route('agent.ping'))
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'server_id' => $server->id,
                'server_name' => $server->name,
            ]);

        $this->assertNotNull($credential->fresh()->last_used_at);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson(route('agent.ping'))
            ->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken(str_repeat('a', 64))
            ->getJson(route('agent.ping'))
            ->assertUnauthorized();
    }

    public function test_revoked_credential_is_rejected(): void
    {
        [, $token, $credential] = $this->createCredential();

        $credential->update([
            'revoked_at' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->getJson(route('agent.ping'))
            ->assertUnauthorized();
    }

    public function test_expired_credential_is_rejected(): void
    {
        [, $token, $credential] = $this->createCredential();

        $credential->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->getJson(route('agent.ping'))
            ->assertUnauthorized();
    }

    /**
     * @return array{Server, string, AgentCredential}
     */
    private function createCredential(): array
    {
        $server = Server::create([
            'name' => 'Test server',
            'hostname' => 'test.example.test',
            'status' => 'pending',
        ]);

        $token = bin2hex(random_bytes(32));

        $credential = $server->agentCredentials()->create([
            'name' => 'Test agent',
            'token_hash' => hash('sha256', $token),
        ]);

        return [$server, $token, $credential];
    }
    public function test_last_used_at_is_updated_on_each_authenticated_request(): void
    {
        [$server, $token, $credential] = $this->createCredential();

        $firstRequestTime = now()->startOfSecond();

        $this->travelTo($firstRequestTime);

        $this->withToken($token)
            ->get('/agent/ping')
            ->assertOk();

        $credential->refresh();

        $this->assertNotNull($credential->last_used_at);
        $this->assertSame(
            $firstRequestTime->toDateTimeString(),
            $credential->last_used_at->toDateTimeString(),
        );

        $secondRequestTime = $firstRequestTime->copy()->addMinute();

        $this->travelTo($secondRequestTime);

        $this->withToken($token)
            ->get('/agent/ping')
            ->assertOk();

        $credential->refresh();

        $this->assertSame(
            $secondRequestTime->toDateTimeString(),
            $credential->last_used_at->toDateTimeString(),
        );
    }
}
