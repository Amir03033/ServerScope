<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\Prediction;
use App\Models\Server;
use App\Models\User;
use App\Services\Analytics\PredictiveAnalyzer;
use App\Services\Analytics\Statistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PredictiveMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        AlertRule::query()->forceDelete();
        $this->server = Server::query()->create(['name' => 'db-01', 'hostname' => 'db-01.example.test', 'last_seen_at' => now()]);
    }

    /**
     * Samples every 15 minutes for $days days.
     *
     * @param  callable(float $daysAgo, int $i): array  $values
     */
    private function history(int $days, callable $values): void
    {
        $rows = [];
        $steps = $days * 96;

        for ($i = $steps; $i >= 0; $i--) {
            $at = now()->subMinutes($i * 15);
            $rows[] = [
                'server_id' => $this->server->id,
                'collected_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
                'memory_total_bytes' => 1000,
                'memory_used_bytes' => 400,
                ...$values($i * 15 / 1440, $i),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert(array_map(fn ($row) => [...$row, 'disks' => isset($row['disks']) ? json_encode($row['disks']) : null], $chunk));
        }
    }

    private function disk(float $percent): array
    {
        return ['disks' => [['path' => '/', 'total_bytes' => 100, 'used_bytes' => (int) $percent, 'available_bytes' => 100 - (int) $percent, 'percent' => round($percent, 2)]]];
    }

    private function analyze(): array
    {
        return app(PredictiveAnalyzer::class)->analyzeServer($this->server->fresh());
    }

    public function test_statistics_helpers(): void
    {
        $this->assertSame(3.0, Statistics::median([5, 1, 3]));
        $this->assertSame(2.5, Statistics::median([1, 2, 3, 4]));
        $this->assertSame(1.0, Statistics::mad([1, 2, 3, 4, 5]));

        $fit = Statistics::linearRegression([[0, 1], [1, 3], [2, 5], [3, 7]]);
        $this->assertEqualsWithDelta(2.0, $fit['slope'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $fit['intercept'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $fit['r_squared'], 1e-9);
        $this->assertNull(Statistics::linearRegression([[1, 1], [1, 2], [1, 3]]));
    }

    public function test_cpu_spike_against_the_baseline_is_reported_with_explanation(): void
    {
        $this->history(8, fn (float $daysAgo, int $i) => ['cpu_percent' => $daysAgo < 1 / 24 ? 92 : 20 + ($i % 7)]);

        $result = $this->analyze();

        $this->assertSame(1, $result['anomalies']);
        $prediction = Prediction::query()->where('kind', 'anomaly')->sole();
        $this->assertSame('cpu_percent', $prediction->metric);
        $this->assertGreaterThan(70, $prediction->observed_value);
        $this->assertEqualsWithDelta(23, $prediction->baseline_value, 1.0);
        $this->assertNotNull($prediction->window_start);
        $this->assertGreaterThanOrEqual(72, $prediction->sample_count);
        $this->assertStringContainsString('mediaan', $prediction->reason);
        $this->assertStringContainsString('z-score', $prediction->reason);

        // Anomalies do not open incidents unless explicitly enabled.
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_minor_deviations_and_insufficient_data_produce_nothing(): void
    {
        // +8 points above a stable baseline: statistically odd, not relevant.
        $this->history(8, fn (float $daysAgo) => ['cpu_percent' => $daysAgo < 1 / 24 ? 28 : 20]);
        $this->assertSame(['anomalies' => 0, 'forecasts' => 0], $this->analyze());

        MetricSample::query()->delete();
        $this->history(2, fn (float $daysAgo) => ['cpu_percent' => $daysAgo < 1 / 24 ? 95 : 10, ...$this->disk(20 + (2 - $daysAgo) * 10)]);
        $this->assertSame(['anomalies' => 0, 'forecasts' => 0], $this->analyze(), 'less than 72 hours of baseline and 3 days of trend');
        $this->assertSame(0, Prediction::query()->count());
    }

    public function test_disk_forecast_with_uncertainty_and_predictive_incident(): void
    {
        $this->history(14, fn (float $daysAgo, int $i) => ['cpu_percent' => 10, ...$this->disk(40 + (14 - $daysAgo) * 3 + (($i % 5) - 2) * 0.1)]);

        $result = $this->analyze();

        $this->assertSame(1, $result['forecasts']);
        $forecast = Prediction::query()->where('kind', 'forecast')->sole();
        $this->assertSame('disk_percent', $forecast->metric);
        $this->assertSame('/', $forecast->dimension);
        $this->assertEqualsWithDelta(3.0, $forecast->slope_per_day, 0.05);
        $this->assertGreaterThan(0.95, $forecast->r_squared);
        $this->assertSame('high', $forecast->confidence);
        $this->assertSame(6, $forecast->horizon_days);
        $this->assertTrue($forecast->eta_low_at->lte($forecast->eta_at));
        $this->assertTrue($forecast->eta_high_at->gte($forecast->eta_at));
        $this->assertStringContainsString('R²', $forecast->reason);

        $incident = Incident::query()->sole();
        $this->assertSame('predictive', $incident->source);
        $this->assertSame('warning', $incident->severity);
        $this->assertStringContainsString('geen vastgestelde storing', $incident->description);

        // Re-running updates the same rows.
        $this->analyze();
        $this->assertSame(1, Prediction::query()->count());
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_predictive_alerts_can_be_disabled_per_server(): void
    {
        $this->server->forceFill(['predictive_alerts_enabled' => false])->save();
        $this->history(14, fn (float $daysAgo) => ['cpu_percent' => 10, ...$this->disk(40 + (14 - $daysAgo) * 3)]);

        $this->analyze();

        $this->assertSame(1, Prediction::query()->count(), 'the forecast is still shown');
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_sensitivity_off_disables_anomaly_detection(): void
    {
        $this->server->forceFill(['anomaly_sensitivity' => 'off'])->save();
        $this->history(8, fn (float $daysAgo) => ['cpu_percent' => $daysAgo < 1 / 24 ? 95 : 10]);

        $this->assertSame(0, $this->analyze()['anomalies']);
    }

    public function test_findings_resolve_when_the_condition_is_gone(): void
    {
        $this->history(8, fn (float $daysAgo, int $i) => ['cpu_percent' => $daysAgo < 1 / 24 ? 92 : 20 + ($i % 7)]);
        $this->analyze();
        $this->assertSame(1, Prediction::query()->current()->count());

        MetricSample::query()->where('collected_at', '>=', now()->subHour())->update(['cpu_percent' => 21]);
        $this->analyze();

        $prediction = Prediction::query()->sole();
        $this->assertSame('resolved', $prediction->status);
        $this->assertNull($prediction->active_key);
        $this->assertNotNull($prediction->resolved_at);
    }

    public function test_website_response_time_and_error_rate_anomalies(): void
    {
        $domain = Domain::query()->create(['name' => 'example.com']);
        $rows = [];

        for ($hour = 7 * 24; $hour >= 1; $hour--) {
            foreach ([10, 30, 50] as $minute) {
                $rows[] = ['domain_id' => $domain->id, 'type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 180 + ($hour % 5) * 10, 'checked_at' => now()->subHours($hour)->addMinutes($minute)];
            }
        }

        foreach (range(1, 6) as $i) {
            $rows[] = ['domain_id' => $domain->id, 'type' => 'http', 'result' => $i <= 3 ? 'down' : 'up', 'success' => $i > 3, 'response_time_ms' => 1900, 'checked_at' => now()->subMinutes($i * 8)];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('domain_checks')->insert($chunk);
        }

        $result = app(PredictiveAnalyzer::class)->analyzeDomain($domain);

        $this->assertSame(2, $result['anomalies']);
        $this->assertEqualsCanonicalizing(['response_time_ms', 'error_rate'], Prediction::query()->pluck('metric')->all());

        $this->actingAs(User::factory()->create())->get(route('domains.show', $domain))->assertOk()->assertSee('Afwijkingen')->assertSee('Responstijd');
    }

    public function test_analyze_command_and_server_page(): void
    {
        $this->history(8, fn (float $daysAgo, int $i) => ['cpu_percent' => $daysAgo < 1 / 24 ? 92 : 20 + ($i % 7)]);

        $this->artisan('serverscope:analyze')->assertSuccessful()->expectsOutputToContain('Afwijkingen: 1');

        $this->actingAs(User::factory()->create())->get(route('servers.show', $this->server))
            ->assertOk()->assertSee('Afwijkingen en prognoses')->assertSee('CPU-gebruik');

        config(['serverscope.predictive.enabled' => false]);
        $this->artisan('serverscope:analyze')->assertSuccessful()->expectsOutputToContain('staat uit');
    }
}
