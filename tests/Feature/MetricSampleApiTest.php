<?php

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricSampleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_agent_can_submit_metrics(): void
    {
        [$server, $token] = $this->createAgent();

        $response = $this->withToken($token)->postJson(
            '/api/agent/metrics',
            [
                'collected_at' => now()->toIso8601String(),
                'cpu_percent' => 42.5,
                'load_1' => 0.75,
                'memory_total_bytes' => 8_000_000_000,
                'memory_used_bytes' => 4_000_000_000,
                'memory_available_bytes' => 4_000_000_000,
                'network_rx_bytes' => 125_000,
                'network_tx_bytes' => 85_000,
                'uptime_seconds' => 86_400,
                'agent_version' => '0.1.0',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Meting opgeslagen.');

        $this->assertDatabaseHas('metric_samples', [
            'server_id' => $server->id,
            'cpu_percent' => 42.5,
        ]);

        $this->assertSame('online', $server->fresh()->status);
        $this->assertNotNull($server->fresh()->last_seen_at);
    }

    public function test_full_agent_payload_is_stored_correctly(): void
    {
        [$server, $token] = $this->createAgent();

        $collectedAt = now()->toIso8601String();

        $payload = [
            'collected_at' => $collectedAt,
            'cpu_percent' => 42.5,
            'load_1' => 0.75,
            'load_5' => 0.60,
            'load_15' => 0.45,
            'memory_total_bytes' => 8_000_000_000,
            'memory_used_bytes' => 4_000_000_000,
            'memory_available_bytes' => 4_000_000_000,
            'swap_total_bytes' => 2_000_000_000,
            'swap_used_bytes' => 500_000_000,
            'disks' => [
                [
                    'path' => '/',
                    'total_bytes' => 100_000,
                    'used_bytes' => 60_000,
                    'available_bytes' => 40_000,
                    'percent' => 60.0,
                ],
                [
                    'path' => '/data',
                    'total_bytes' => 200_000,
                    'used_bytes' => 125_000,
                    'available_bytes' => 75_000,
                    'percent' => 62.5,
                ],
            ],
            'network_rx_bytes' => 125_000,
            'network_tx_bytes' => 85_000,
            'uptime_seconds' => 86_400,
            'agent_version' => '0.1.0',
        ];

        $response = $this->withToken($token)->postJson(
            '/api/agent/metrics',
            $payload,
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Meting opgeslagen.')
            ->assertJsonPath('metric_sample_id', fn ($id) => is_int($id) || ctype_digit((string) $id));

        $sample = MetricSample::query()
            ->where('server_id', $server->id)
            ->firstOrFail();

        $this->assertEquals(42.5, $sample->cpu_percent);
        $this->assertEquals(0.75, $sample->load_1);
        $this->assertEquals(0.60, $sample->load_5);
        $this->assertEquals(0.45, $sample->load_15);

        $this->assertSame(8_000_000_000, $sample->memory_total_bytes);
        $this->assertSame(4_000_000_000, $sample->memory_used_bytes);
        $this->assertSame(4_000_000_000, $sample->memory_available_bytes);

        $this->assertSame(2_000_000_000, $sample->swap_total_bytes);
        $this->assertSame(500_000_000, $sample->swap_used_bytes);

        $this->assertEquals($payload['disks'], $sample->disks);

        $this->assertSame(125_000, $sample->network_rx_bytes);
        $this->assertSame(85_000, $sample->network_tx_bytes);
        $this->assertSame(86_400, $sample->uptime_seconds);
        $this->assertSame('0.1.0', $sample->agent_version);

        $this->assertSame('online', $server->fresh()->status);
        $this->assertNotNull($server->fresh()->last_seen_at);
        $this->assertSame('0.1.0', $server->fresh()->agent_version);
    }

    public function test_agent_cannot_submit_metrics_without_a_token(): void
    {
        $this->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => 10,
        ])->assertUnauthorized();
    }

    public function test_invalid_cpu_percentage_is_rejected(): void
    {
        [, $token] = $this->createAgent();

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => 150,
        ])->assertUnprocessable();
    }

    public function test_old_measurements_are_rejected(): void
    {
        [, $token] = $this->createAgent();

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->subMinutes(20)->toIso8601String(),
            'cpu_percent' => 10,
        ])->assertUnprocessable();
    }

    public function test_agent_cannot_choose_another_server(): void
    {
        [$server, $token] = $this->createAgent();

        $otherServer = Server::create([
            'name' => 'Other server',
            'hostname' => 'other.example.test',
            'status' => 'pending',
        ]);

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'server_id' => $otherServer->id,
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => 20,
        ])->assertCreated();

        $this->assertDatabaseHas('metric_samples', [
            'server_id' => $server->id,
            'cpu_percent' => 20,
        ]);

        $this->assertDatabaseMissing('metric_samples', [
            'server_id' => $otherServer->id,
            'cpu_percent' => 20,
        ]);
    }

    public function test_duplicate_sample_timestamp_is_rejected(): void
    {
        [$server, $token] = $this->createAgent();
        $timestamp = now()->toIso8601String();

        $payload = [
            'collected_at' => $timestamp,
            'cpu_percent' => 10,
        ];

        $this->withToken($token)
            ->postJson('/api/agent/metrics', $payload)
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/agent/metrics', $payload)
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Voor dit tijdstip bestaat al een meting.',
            );

        $this->assertDatabaseCount('metric_samples', 1);

        $this->assertDatabaseHas('metric_samples', [
            'server_id' => $server->id,
            'cpu_percent' => 10,
        ]);
    }

    public function test_memory_used_cannot_exceed_memory_total(): void
    {
        [, $token] = $this->createAgent();

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'memory_total_bytes' => 1_000,
            'memory_used_bytes' => 1_001,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('memory_used_bytes');
    }

    public function test_memory_available_cannot_exceed_memory_total(): void
    {
        [, $token] = $this->createAgent();

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'memory_total_bytes' => 1_000,
            'memory_available_bytes' => 1_001,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('memory_available_bytes');
    }

    public function test_disk_values_cannot_exceed_disk_total(): void
    {
        [, $token] = $this->createAgent();

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'disks' => [
                [
                    'path' => '/',
                    'total_bytes' => 1_000,
                    'used_bytes' => 800,
                    'available_bytes' => 300,
                    'percent' => 80,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('disks.0');
    }

    private function createAgent(): array
    {
        $server = Server::create([
            'name' => 'API test server',
            'hostname' => 'api.example.test',
            'status' => 'pending',
        ]);

        $token = bin2hex(random_bytes(32));

        $server->agentCredentials()->create([
            'name' => 'Test agent',
            'token_hash' => hash('sha256', $token),
        ]);

        return [$server, $token];
    }
}
