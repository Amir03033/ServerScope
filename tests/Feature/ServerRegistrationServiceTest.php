<?php

namespace Tests\Feature;

use App\Models\AgentCredential;
use App\Models\Server;
use App\Services\ServerRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_server_and_issues_a_token(): void
    {
        $result = app(ServerRegistrationService::class)->register([
            'name' => 'Production server',
            'hostname' => 'server.example.test',
            'ip_address' => '192.0.2.10',
            'operating_system' => 'Ubuntu 24.04',
        ]);

        $server = $result['server'];
        $token = $result['token'];

        $this->assertInstanceOf(Server::class, $server);
        $this->assertSame('pending', $server->status);
        $this->assertSame(64, strlen($token));

        $credential = AgentCredential::query()
            ->where('server_id', $server->id)
            ->sole();

        $this->assertSame(
            hash('sha256', $token),
            $credential->token_hash,
        );

        $this->assertDatabaseMissing('agent_credentials', [
            'server_id' => $server->id,
            'token_hash' => $token,
        ]);
    }

    public function test_each_registration_issues_a_different_token(): void
    {
        $service = app(ServerRegistrationService::class);

        $first = $service->register([
            'name' => 'Server One',
            'hostname' => 'one.example.test',
        ]);

        $second = $service->register([
            'name' => 'Server Two',
            'hostname' => 'two.example.test',
        ]);

        $this->assertNotSame(
            $first['token'],
            $second['token'],
        );
    }

    public function test_it_rejects_a_missing_server_name(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ServerRegistrationService::class)->register([
            'hostname' => 'server.example.test',
        ]);
    }

    public function test_it_rejects_a_missing_hostname(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ServerRegistrationService::class)->register([
            'name' => 'Production server',
        ]);
    }
}
