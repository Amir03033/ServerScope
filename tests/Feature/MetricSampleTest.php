<?php

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricSampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_metric_sample_belongs_to_a_server(): void
    {
        $server = Server::create([
            'name' => 'Metrics test server',
            'hostname' => 'metrics.example.test',
            'status' => 'pending',
        ]);

        $sample = $server->metricSamples()->create([
            'cpu_percent' => 42.50,
            'load_1' => 0.75,
            'memory_total_bytes' => 8_000_000_000,
            'memory_used_bytes' => 4_000_000_000,
            'network_rx_bytes' => 125_000,
            'network_tx_bytes' => 85_000,
            'uptime_seconds' => 86_400,
            'collected_at' => now(),
        ]);

        $this->assertDatabaseHas('metric_samples', [
            'id' => $sample->id,
            'server_id' => $server->id,
            'cpu_percent' => 42.50,
        ]);

        $this->assertSame(
            $server->id,
            $sample->server->id,
        );

        $this->assertCount(1, $server->metricSamples);
    }

    public function test_deleting_a_server_deletes_its_metric_samples(): void
    {
        $server = Server::create([
            'name' => 'Delete test server',
            'hostname' => 'delete.example.test',
            'status' => 'pending',
        ]);

        $sample = $server->metricSamples()->create([
            'cpu_percent' => 10,
            'collected_at' => now(),
        ]);

        // Permanent deletion; delete() is a soft delete since server management.
        $server->forceDelete();

        $this->assertDatabaseMissing('metric_samples', [
            'id' => $sample->id,
        ]);
    }
}
