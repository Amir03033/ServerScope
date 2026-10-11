<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_hash_is_hidden_from_json(): void
    {
        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'test.example.test',
        ]);

        $credential = $server->agentCredentials()->create([
            'name' => 'Test credential',
            'token_hash' => hash('sha256', 'example-test-token'),
        ]);

        $json = $credential->toJson();

        $this->assertStringNotContainsString(
            $credential->token_hash,
            $json
        );

        $this->assertStringNotContainsString(
            'token_hash',
            $json
        );
    }

    public function test_credential_belongs_to_its_server(): void
    {
        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'test.example.test',
        ]);

        $credential = $server->agentCredentials()->create([
            'name' => 'Test credential',
            'token_hash' => hash('sha256', 'example-test-token'),
        ]);

        $this->assertTrue(
            $credential->server->is($server)
        );
    }
}
