<?php

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_server_detail(): void
    {
        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'vps.example.test',
        ]);

        $this->get(route('servers.show', $server))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_server_detail(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'vps.example.test',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertViewIs('servers.show')
            ->assertViewHas('server', $server);
    }

    public function test_server_detail_uses_only_that_servers_metrics(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'VPS Alpha',
            'hostname' => 'alpha.example.test',
        ]);

        $otherServer = Server::create([
            'name' => 'VPS Beta',
            'hostname' => 'beta.example.test',
        ]);

        MetricSample::create([
            'server_id' => $server->id,
            'collected_at' => now()->subMinutes(2),
            'cpu_percent' => 25,
            'memory_total_bytes' => 8_000_000_000,
            'memory_used_bytes' => 2_000_000_000,
        ]);

        MetricSample::create([
            'server_id' => $otherServer->id,
            'collected_at' => now()->subMinutes(1),
            'cpu_percent' => 90,
            'memory_total_bytes' => 8_000_000_000,
            'memory_used_bytes' => 7_000_000_000,
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', [
                'server' => $server,
                'range' => '24h',
            ]))
            ->assertOk()
            ->assertViewHas('samples', function ($samples) use ($server) {
                return $samples->count() === 1
                    && $samples->first()->server_id === $server->id;
            });
    }

    public function test_invalid_time_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'vps.example.test',
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', [
                'server' => $server,
                'range' => 'all-time',
            ]))
            ->assertSessionHasErrors('range');
    }
}
