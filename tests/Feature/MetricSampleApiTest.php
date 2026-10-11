<?php

namespace Tests\Feature;

use App\Models\AgentCredential;
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

        $response->assertCreated();

        $this->assertDatabaseHas('metric_samples', [
            'server_id' => $server->id,
            'cpu_percent' => 42.5,
        ]);

        $this->assertSame(
            'online',
            $server->fresh()->status,
        );

        $this->assertNotNull($server->fresh()->last_seen_at);
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
        [, $token] = $this->createAgent();
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
            ->assertStatus(409);
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
