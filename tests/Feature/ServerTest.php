<?php

namespace Tests\Feature;

use App\Models\AgentCredential;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_can_be_created(): void
    {
        $server = Server::create([
            'name' => 'Production VPS',
            'hostname' => 'vps.example.test',
            'ip_address' => '192.0.2.10',
            'operating_system' => 'Ubuntu 24.04',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'name' => 'Production VPS',
            'status' => 'pending',
        ]);
    }

    public function test_server_can_have_multiple_credentials(): void
    {
        $server = Server::create([
            'name' => 'Production VPS',
            'hostname' => 'vps.example.test',
        ]);

        $server->agentCredentials()->createMany([
            [
                'name' => 'Primary agent',
                'token_hash' => hash('sha256', 'test-token-one'),
            ],
            [
                'name' => 'Replacement agent',
                'token_hash' => hash('sha256', 'test-token-two'),
            ],
        ]);

        $this->assertCount(2, $server->agentCredentials);
    }

    public function test_deleting_server_deletes_its_credentials(): void
    {
        $server = Server::create([
            'name' => 'Temporary VPS',
            'hostname' => 'temporary.example.test',
        ]);

        $credential = $server->agentCredentials()->create([
            'name' => 'Test agent',
            'token_hash' => hash('sha256', 'temporary-test-token'),
        ]);

        // Permanent deletion; delete() is a soft delete since server management.
        $server->forceDelete();

        $this->assertDatabaseMissing('servers', [
            'id' => $server->id,
        ]);

        $this->assertDatabaseMissing('agent_credentials', [
            'id' => $credential->id,
        ]);
    }
}
