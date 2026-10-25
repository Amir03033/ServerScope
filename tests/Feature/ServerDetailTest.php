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

    public function test_long_ranges_are_downsampled_across_the_whole_range(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'Test VPS',
            'hostname' => 'vps.example.test',
            'last_seen_at' => now(),
        ]);

        $now = now()->startOfMinute();
        $rows = [];

        // 2,500 samples spread over the last ~29 days.
        for ($i = 2499; $i >= 0; $i--) {
            $rows[] = [
                'server_id' => $server->id,
                'cpu_percent' => $i === 0 ? 77 : 10,
                'network_rx_bytes' => $i === 0 ? 2_000 : 1_000,
                'collected_at' => $now->copy()->subMinutes($i * 16),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }

        $response = $this->actingAs($user)
            ->get(route('servers.show', [
                'server' => $server,
                'range' => '30d',
            ]))
            ->assertOk()
            ->assertViewHas('sampleCount', 2500);

        $samples = $response->viewData('samples');

        $this->assertLessThanOrEqual(2001, $samples->count());
        $this->assertTrue(
            $samples->first()->collected_at->lt($now->copy()->subDays(25)),
        );
        $this->assertTrue(
            $samples->last()->collected_at->eq($now),
        );
        $this->assertSame(77.0, (float) $samples->last()->cpu_percent);

        // Rate uses the directly preceding sample (16 minutes earlier).
        $this->assertEqualsWithDelta(
            1_000 / 960,
            $response->viewData('networkRates')['rx'],
            0.0001,
        );
    }
}
