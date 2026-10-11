<?php

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_empty_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Servers')
            ->assertSee('Nog geen servers geregistreerd')
            ->assertSee('Totaal servers');
    }

    public function test_dashboard_displays_server_and_latest_metrics(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'Production VPS',
            'hostname' => 'vps.example.test',
            'status' => 'online',
            'last_seen_at' => now(),
            'agent_version' => '0.1.0',
        ]);

        MetricSample::create([
            'server_id' => $server->id,
            'collected_at' => now(),
            'cpu_percent' => 42.5,
            'memory_total_bytes' => 8_000_000_000,
            'memory_used_bytes' => 4_000_000_000,
            'memory_available_bytes' => 4_000_000_000,
            'agent_version' => '0.1.0',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Production VPS')
            ->assertSee('vps.example.test')
            ->assertSee('Online')
            ->assertSee('42.5%')
            ->assertSee('50.0%');
    }

    public function test_dashboard_counts_pending_and_offline_servers(): void
    {
        $user = User::factory()->create();

        Server::create([
            'name' => 'New server',
            'hostname' => 'new.example.test',
            'status' => 'pending',
        ]);

        Server::create([
            'name' => 'Old server',
            'hostname' => 'old.example.test',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('New server')
            ->assertSee('Old server')
            ->assertSee('Offline')
            ->assertSee('Pending');
    }
}
