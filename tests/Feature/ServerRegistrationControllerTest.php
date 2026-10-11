<?php

namespace Tests\Feature;

use App\Models\AgentCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_register_a_server(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('servers.register'),
            [
                'name' => 'Production server',
                'hostname' => 'server.example.test',
                'ip_address' => '192.0.2.10',
                'operating_system' => 'Ubuntu 24.04',
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('server.name', 'Production server')
            ->assertJsonPath('server.status', 'pending')
            ->assertJsonPath('message', 'Server succesvol geregistreerd.')
            ->assertJsonStructure([
                'server' => ['id', 'name', 'hostname', 'status'],
                'agent_token',
            ]);

        $token = $response->json('agent_token');

        $this->assertSame(64, strlen($token));

        $this->assertDatabaseHas('agent_credentials', [
            'server_id' => $response->json('server.id'),
            'token_hash' => hash('sha256', $token),
        ]);
    }

    public function test_non_admin_cannot_register_a_server(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->postJson(route('servers.register'), [
                'name' => 'Unauthorized server',
                'hostname' => 'unauthorized.example.test',
            ])
            ->assertForbidden();
    }

    public function test_guest_cannot_register_a_server(): void
    {
        $this->postJson(route('servers.register'), [
            'name' => 'Guest server',
            'hostname' => 'guest.example.test',
        ])->assertUnauthorized();
    }

    public function test_invalid_server_data_is_rejected(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('servers.register'), [
                'name' => '',
                'hostname' => '',
                'ip_address' => 'not-an-ip-address',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'hostname',
                'ip_address',
            ]);
    }

    public function test_client_cannot_set_server_status(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('servers.register'),
            [
                'name' => 'Test server',
                'hostname' => 'test.example.test',
                'status' => 'online',
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('server.status', 'pending');
    }
}
