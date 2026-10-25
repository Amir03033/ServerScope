<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\AlertDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Samples are created relative to now(); a second boundary between
        // two now() calls would shift the evaluation window.
        $this->freezeSecond();
    }

    public function test_cpu_alert_is_created_when_threshold_is_exceeded_for_required_duration(): void
    {
        $server = $this->createServer();

        $rule = $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 2,
        ]);

        $this->createSample($server, now()->subMinutes(2), 90);
        $this->createSample($server, now()->subMinute(), 92);

        $latestSample = $this->createSample($server, now(), 95);

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseCount('alerts', 1);

        $this->assertDatabaseHas('alerts', [
            'server_id' => $server->id,
            'alert_rule_id' => $rule->id,
            'metric' => 'cpu_percent',
            'status' => 'active',
            'threshold' => 85,
            'observed_value' => 95,
        ]);
    }

    public function test_cpu_alert_is_not_created_when_threshold_has_not_been_exceeded_long_enough(): void
    {
        $server = $this->createServer();

        $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 5,
        ]);

        $this->createSample($server, now()->subMinutes(2), 90);

        $latestSample = $this->createSample($server, now(), 95);

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_existing_active_alert_is_resolved_when_cpu_returns_below_threshold(): void
    {
        $server = $this->createServer();

        $rule = $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 1,
        ]);

        $alert = Alert::create([
            'server_id' => $server->id,
            'alert_rule_id' => $rule->id,
            'metric' => 'cpu_percent',
            'observed_value' => 95,
            'threshold' => 85,
            'status' => 'active',
            'triggered_at' => now()->subMinutes(2),
        ]);

        $sample = $this->createSample($server, now(), 40);

        app(AlertDetectionService::class)->evaluate($sample);

        $alert->refresh();

        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(40.0, $alert->observed_value);
    }

    public function test_repeated_high_cpu_samples_do_not_create_duplicate_active_alerts(): void
    {
        $server = $this->createServer();

        $rule = $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 1,
        ]);

        $this->createSample($server, now()->subMinute(), 90);

        $firstSample = $this->createSample($server, now(), 92);

        $service = app(AlertDetectionService::class);
        $service->evaluate($firstSample);

        $secondSample = $this->createSample(
            $server,
            now()->addMinute(),
            95,
        );

        $service->evaluate($secondSample);

        $this->assertDatabaseCount('alerts', 1);

        $this->assertSame(
            1,
            Alert::query()
                ->where('server_id', $server->id)
                ->where('alert_rule_id', $rule->id)
                ->where('status', 'active')
                ->count(),
        );
    }

    public function test_memory_alert_uses_memory_percentage(): void
    {
        $server = $this->createServer();

        $rule = $this->createRule([
            'metric' => 'memory_percent',
            'threshold' => 80,
            'duration_minutes' => 1,
        ]);

        $this->createSample(
            $server,
            now()->subMinute(),
            10,
            memoryTotal: 1000,
            memoryUsed: 850,
        );

        $latestSample = $this->createSample(
            $server,
            now(),
            20,
            memoryTotal: 1000,
            memoryUsed: 900,
        );

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseHas('alerts', [
            'server_id' => $server->id,
            'alert_rule_id' => $rule->id,
            'metric' => 'memory_percent',
            'status' => 'active',
            'observed_value' => 90,
        ]);
    }

    public function test_disabled_alert_rules_are_ignored(): void
    {
        $server = $this->createServer();

        $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 1,
            'is_enabled' => false,
        ]);

        $this->createSample($server, now()->subMinute(), 90);

        $latestSample = $this->createSample($server, now(), 95);

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_disk_alert_stores_mountpoint_separately(): void
    {
        $server = $this->createServer();

        $mountpoint = '/var/lib/docker/volumes/a-very-long-volume-name/example';

        $this->createRule([
            'name' => 'High disk usage',
            'metric' => 'disk_percent',
            'threshold' => 85,
            'duration_minutes' => 1,
        ]);

        $this->createSampleWithDisks(
            $server,
            now()->subMinute(),
            [$mountpoint => 90],
        );

        $latestSample = $this->createSampleWithDisks(
            $server,
            now(),
            [$mountpoint => 95],
        );

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseHas('alerts', [
            'server_id' => $server->id,
            'metric' => 'disk_percent',
            'dimension' => $mountpoint,
            'status' => 'active',
            'observed_value' => 95,
        ]);
    }

    public function test_missing_measurement_interval_prevents_alert_creation(): void
    {
        $server = $this->createServer();

        $this->createRule([
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'duration_minutes' => 2,
        ]);

        $this->createSample($server, now()->subMinutes(2), 90);

        // A two-minute gap exceeds the allowed 90-second interval.
        $latestSample = $this->createSample($server, now(), 95);

        app(AlertDetectionService::class)->evaluate($latestSample);

        $this->assertDatabaseCount('alerts', 0);
    }

    private function createServer(): Server
    {
        return Server::create([
            'name' => 'Alert Test Server',
            'hostname' => 'alerts.example.test',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    private function createRule(array $overrides = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'name' => 'High CPU Usage',
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ], $overrides));
    }

    private function createSample(
        Server $server,
               $collectedAt,
        float $cpu,
        ?int $memoryTotal = null,
        ?int $memoryUsed = null,
    ): MetricSample {
        return MetricSample::create([
            'server_id' => $server->id,
            'collected_at' => $collectedAt,
            'cpu_percent' => $cpu,
            'memory_total_bytes' => $memoryTotal,
            'memory_used_bytes' => $memoryUsed,
            'memory_available_bytes' => $memoryTotal !== null
            && $memoryUsed !== null
                ? $memoryTotal - $memoryUsed
                : null,
        ]);
    }

    private function createSampleWithDisks(
        Server $server,
               $collectedAt,
        array $disks,
    ): MetricSample {
        return MetricSample::create([
            'server_id' => $server->id,
            'collected_at' => $collectedAt,
            'cpu_percent' => 10,
            'disks' => collect($disks)
                ->map(fn (float $percent, string $path): array => [
                    'path' => $path,
                    'percent' => $percent,
                    'total_bytes' => 1000,
                    'used_bytes' => (int) ($percent * 10),
                    'available_bytes' => 1000 - (int) ($percent * 10),
                ])
                ->values()
                ->all(),
        ]);
    }
}
