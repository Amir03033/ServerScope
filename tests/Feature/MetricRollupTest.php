<?php

namespace Tests\Feature;

use App\Jobs\RollupServerMetrics;
use App\Models\AlertRule;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Prediction;
use App\Models\Server;
use App\Models\Team;
use App\Services\Analytics\MetricHistory;
use App\Services\Analytics\MetricRollups;
use App\Services\Analytics\PredictiveAnalyzer;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MetricRollupTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 12:30:00');
        AlertRule::query()->forceDelete();
        $this->server = Server::query()->create(['name' => 'web-01', 'hostname' => 'web-01.example.test', 'last_seen_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * One sample every $step seconds in [$from, $to).
     *
     * @param  callable(int $i, CarbonImmutable $at): array  $values
     */
    private function samples(Server $server, string $from, string $to, int $step = 60, ?callable $values = null): void
    {
        $rows = [];
        $at = CarbonImmutable::parse($from, 'UTC');
        $end = CarbonImmutable::parse($to, 'UTC');

        for ($i = 0; $at->lt($end); $i++, $at = $at->addSeconds($step)) {
            $row = [
                'server_id' => $server->id,
                'collected_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
                'cpu_percent' => ($i % 10) * 10,
                'load_1' => 1.5,
                'memory_total_bytes' => 1000,
                'memory_used_bytes' => 400 + ($i % 2) * 100,
                'swap_total_bytes' => 0,
                'swap_used_bytes' => 0,
                'network_rx_bytes' => 1024 * 60 * $i,
                'network_tx_bytes' => 2048 * 60 * $i,
                'disks' => [['path' => '/', 'percent' => 50], ['path' => '/var', 'percent' => 70 + ($i % 2)]],
                ...($values ? $values($i, $at) : []),
            ];
            $row['disks'] = json_encode($row['disks']);
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }
    }

    private function rollup(string $metric, string $hour, string $dimension = '', string $period = 'hour'): ?MetricRollup
    {
        return MetricRollup::query()->where('server_id', $this->server->id)->where('period', $period)
            ->where('bucket_start', CarbonImmutable::parse($hour, 'UTC'))->where('metric', $metric)->where('dimension', $dimension)->first();
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshot(): array
    {
        return DB::table('metric_rollups')->orderBy('period')->orderBy('bucket_start')->orderBy('metric')->orderBy('dimension')
            ->get(['server_id', 'team_id', 'period', 'bucket_start', 'metric', 'dimension', 'count', 'sum', 'min', 'max'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function test_closed_hours_are_aggregated_with_exact_counts_sums_and_extremes(): void
    {
        $this->samples($this->server, '2026-11-10 09:00:00', '2026-11-10 12:30:00');

        $result = app(MetricRollups::class)->rollup($this->server);

        // 12:30 - 11 minutes = 12:19, so 12:00-13:00 is still open.
        $this->assertSame(3, $result['hours']);
        $this->assertSame('2026-11-10T12:00:00+00:00', $this->server->fresh()->metrics_rolled_up_until->toIso8601String());
        $this->assertNull($this->rollup('cpu_percent', '2026-11-10 12:00:00'));

        $cpu = $this->rollup('cpu_percent', '2026-11-10 10:00:00');
        $this->assertSame(60, $cpu->count);
        $this->assertEqualsWithDelta(60 * 45, $cpu->sum, 1e-9); // 0..90 step 10, six times
        $this->assertSame(0.0, $cpu->min);
        $this->assertSame(90.0, $cpu->max);
        $this->assertEqualsWithDelta(45.0, $cpu->mean(), 1e-9);

        $memory = $this->rollup('memory_percent', '2026-11-10 10:00:00');
        $this->assertEqualsWithDelta(45.0, $memory->mean(), 1e-9);
        $this->assertSame(40.0, $memory->min);
        $this->assertSame(50.0, $memory->max);

        $this->assertSame(70.0, $this->rollup('disk_percent', '2026-11-10 10:00:00', '/var')->min);
        $this->assertSame(71.0, $this->rollup('disk_percent', '2026-11-10 10:00:00', '/var')->max);
        $this->assertEqualsWithDelta(50.0, $this->rollup('disk_percent', '2026-11-10 10:00:00', '/')->mean(), 1e-9);

        // Counters: 60 KB per minute received = 1 KB/s; every sample of
        // 10:00 has a predecessor (also the first: 09:59).
        $rx = $this->rollup('network_rx', '2026-11-10 10:00:00');
        $this->assertSame(60, $rx->count);
        $this->assertEqualsWithDelta(1.0, $rx->mean(), 1e-9);
        $this->assertEqualsWithDelta(2.0, $this->rollup('network_tx', '2026-11-10 10:00:00')->mean(), 1e-9);
        // The very first sample (09:00) has no predecessor.
        $this->assertSame(59, $this->rollup('network_rx', '2026-11-10 09:00:00')->count);

        // Swap total 0: no percentage at all instead of a division by zero.
        $this->assertNull($this->rollup('swap_percent', '2026-11-10 10:00:00'));

        // Daily row = merge of the three closed hours.
        $day = $this->rollup('cpu_percent', '2026-11-10 00:00:00', '', 'day');
        $this->assertSame(180, $day->count);
        $this->assertEqualsWithDelta(180 * 45, $day->sum, 1e-9);
        $this->assertSame(0.0, $day->min);
        $this->assertSame(90.0, $day->max);
        $this->assertSame($this->server->team_id, $day->team_id);
    }

    public function test_running_again_or_recomputing_creates_no_duplicates_and_changes_nothing(): void
    {
        $this->samples($this->server, '2026-11-10 08:00:00', '2026-11-10 12:30:00');
        $rollups = app(MetricRollups::class);

        $rollups->rollup($this->server);
        $first = $this->snapshot();

        $this->assertSame(0, $rollups->rollup($this->server->fresh())['hours']);
        $rollups->rollup($this->server->fresh(), from: Carbon::parse('2026-11-10 08:00:00'));
        $this->artisan('serverscope:rollup')->assertSuccessful();
        $this->artisan('serverscope:rollup', ['--from' => '2026-11-10 00:00:00'])->assertSuccessful();

        $this->assertSame($first, $this->snapshot());
        $this->assertSame(0, DB::table('metric_rollups')
            ->select('server_id', 'period', 'bucket_start', 'metric', 'dimension')
            ->groupBy('server_id', 'period', 'bucket_start', 'metric', 'dimension')
            ->havingRaw('count(*) > 1')->get()->count());
        // A recomputation of older hours never moves the watermark back.
        $this->assertSame('2026-11-10T12:00:00+00:00', $this->server->fresh()->metrics_rolled_up_until->toIso8601String());
    }

    public function test_an_hour_is_only_aggregated_once_no_late_sample_can_arrive(): void
    {
        $this->samples($this->server, '2026-11-10 11:00:00', '2026-11-10 11:59:00');
        $rollups = app(MetricRollups::class);

        Carbon::setTestNow('2026-11-10 12:10:00');
        $rollups->rollup($this->server);
        $this->assertNull($this->rollup('cpu_percent', '2026-11-10 11:00:00'));

        // Still within the ingest window at 12:10, so it is accepted.
        $this->samples($this->server, '2026-11-10 11:59:00', '2026-11-10 12:00:00');

        Carbon::setTestNow('2026-11-10 12:11:00');
        $rollups->rollup($this->server->fresh());
        $this->assertSame(60, $this->rollup('cpu_percent', '2026-11-10 11:00:00')->count);
    }

    public function test_a_failed_run_keeps_completed_chunks_and_a_retry_finishes_the_rest_exactly_once(): void
    {
        // 30 closed hours = two chunks of 24 hours.
        $this->samples($this->server, '2026-11-09 06:00:00', '2026-11-10 12:00:00', step: 300);

        $failing = new class extends MetricRollups
        {
            public int $calls = 0;

            public function rollupDays(Server $server, CarbonImmutable $start, CarbonImmutable $end): int
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('database went away');
                }

                return parent::rollupDays($server, $start, $end);
            }
        };

        try {
            $failing->rollup($this->server);
            $this->fail('Expected the second chunk to fail.');
        } catch (RuntimeException) {
        }

        // The failed chunk was rolled back as a whole; the first stays.
        $this->assertSame('2026-11-10T06:00:00+00:00', $this->server->fresh()->metrics_rolled_up_until->toIso8601String());
        $this->assertNull($this->rollup('cpu_percent', '2026-11-10 06:00:00'));

        // The queued job is the retry.
        (new RollupServerMetrics($this->server->id))->handle(app(MetricRollups::class));
        (new RollupServerMetrics($this->server->id))->handle(app(MetricRollups::class));

        $this->assertSame('2026-11-10T12:00:00+00:00', $this->server->fresh()->metrics_rolled_up_until->toIso8601String());
        $this->assertSame(30, MetricRollup::query()->where('period', 'hour')->where('metric', 'cpu_percent')->count());
        $this->assertSame([12], MetricRollup::query()->where('period', 'hour')->where('metric', 'cpu_percent')->distinct()->pluck('count')->all());
        $this->assertSame(18 * 12, $this->rollup('cpu_percent', '2026-11-09 00:00:00', '', 'day')->count);
        $this->assertSame(12 * 12, $this->rollup('cpu_percent', '2026-11-10 00:00:00', '', 'day')->count);
    }

    public function test_a_bucket_is_never_replaced_by_one_built_from_fewer_samples(): void
    {
        $this->samples($this->server, '2026-11-10 09:00:00', '2026-11-10 12:00:00');
        $rollups = app(MetricRollups::class);
        $rollups->rollup($this->server);

        // Raw retention removes part of an hour that was already aggregated.
        MetricSample::query()->where('collected_at', '<', '2026-11-10 09:30:00')->delete();
        $rollups->rollup($this->server->fresh(), from: Carbon::parse('2026-11-10 09:00:00'));

        $this->assertSame(60, $this->rollup('cpu_percent', '2026-11-10 09:00:00')->count);
        $this->assertSame(180, $this->rollup('cpu_percent', '2026-11-10 00:00:00', '', 'day')->count);
    }

    public function test_the_first_run_backfills_a_bounded_period(): void
    {
        config(['serverscope.rollups.initial_backfill_days' => 2]);
        $this->samples($this->server, '2026-11-01 00:00:00', '2026-11-10 12:00:00', step: 3600);

        app(MetricRollups::class)->rollup($this->server);

        $this->assertSame('2026-11-08 12:00:00', MetricRollup::query()->where('period', 'hour')->min('bucket_start'));

        // Older history on request.
        $this->artisan('serverscope:rollup', ['--from' => '2026-11-01'])->assertSuccessful();
        $this->assertSame('2026-11-01 00:00:00', MetricRollup::query()->where('period', 'hour')->min('bucket_start'));
    }

    public function test_the_queue_mode_dispatches_one_unique_job_per_server(): void
    {
        Queue::fake();
        $other = Server::query()->create(['name' => 'web-02', 'hostname' => 'web-02.example.test', 'last_seen_at' => now()]);
        Server::query()->create(['name' => 'never-seen', 'hostname' => 'nieuw.example.test']);

        $this->artisan('serverscope:rollup', ['--queue' => true])->assertSuccessful();
        $this->artisan('serverscope:rollup', ['--queue' => true])->assertSuccessful();

        // The second run finds the jobs of the first still pending.
        Queue::assertPushed(RollupServerMetrics::class, 2);
        Queue::assertPushed(RollupServerMetrics::class, fn (RollupServerMetrics $job) => $job->serverId === $other->id);
        $this->assertSame((string) $other->id, (new RollupServerMetrics($other->id))->uniqueId());
    }

    public function test_history_from_rollups_equals_history_from_raw_samples(): void
    {
        // 5-minute samples over 9 days, with daily cycles and growth, as
        // read by anomaly detection (7 days) and forecasts (14 days).
        $this->samples($this->server, '2026-11-01 09:13:00', '2026-11-10 12:29:00', step: 300, values: fn (int $i, CarbonImmutable $at) => [
            'cpu_percent' => round(30 + 20 * sin($i / 12), 2),
            'load_1' => round(1 + ($i % 7) / 10, 2),
            'swap_total_bytes' => 1000,
            'swap_used_bytes' => 100 + $i % 50,
            'network_rx_bytes' => 1000 * $i * $i,
            'disks' => [['path' => '/', 'percent' => round(40 + $i / 500, 2)]],
        ]);
        $history = app(MetricHistory::class);
        $windows = [
            [now()->subDays(7)->subHour(), now()->subHour()],
            [now()->subDays(14), now()],
            [now()->subHour(), now()],
        ];

        $raw = array_map(fn ($w) => $history->buckets($this->server->fresh(), $w[0], $w[1], 3600), $windows);

        app(MetricRollups::class)->rollup($this->server);
        $server = $this->server->fresh();
        $this->assertNotNull($server->metrics_rolled_up_until);

        foreach ($windows as $index => [$from, $to]) {
            $hybrid = $history->buckets($server, $from, $to, 3600);

            // Same metrics (order is irrelevant to the analyzer).
            $this->assertEqualsCanonicalizing(array_keys($raw[$index]), array_keys($hybrid), "window {$index}");

            foreach ($raw[$index] as $metric => $hours) {
                $this->assertSame(array_keys($hours), array_keys($hybrid[$metric]), "{$metric} hours, window {$index}");

                foreach ($hours as $hour => $bucket) {
                    $this->assertSame($bucket['count'], $hybrid[$metric][$hour]['count'], "{$metric} @ {$hour}");
                    $this->assertEqualsWithDelta($bucket['mean'], $hybrid[$metric][$hour]['mean'], 1e-6, "{$metric} @ {$hour}");
                }
            }
        }

        // The rollups really are used: changing a raw sample inside an
        // aggregated hour no longer changes the history.
        MetricSample::query()->where('collected_at', '2026-11-08 10:03:00')->update(['cpu_percent' => 100]);
        $this->assertEqualsWithDelta(
            $raw[0]['cpu_percent'][CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC')->getTimestamp()]['mean'],
            $history->hourly($server, $windows[0][0], $windows[0][1])['cpu_percent'][CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC')->getTimestamp()],
            1e-6,
        );

        // Switching the read path off falls back to raw samples.
        config(['serverscope.rollups.read' => false]);
        $this->assertNotEqualsWithDelta(
            $raw[0]['cpu_percent'][CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC')->getTimestamp()]['mean'],
            $history->hourly($server, $windows[0][0], $windows[0][1])['cpu_percent'][CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC')->getTimestamp()],
            1e-6,
        );
    }

    public function test_the_analyzer_reaches_the_same_findings_from_rollups(): void
    {
        // Disk filling up steadily plus a CPU spike in the last hour.
        $this->samples($this->server, '2026-10-30 12:30:00', '2026-11-10 12:30:00', step: 300, values: fn (int $i, CarbonImmutable $at) => [
            'cpu_percent' => $at->gte(CarbonImmutable::parse('2026-11-10 11:30:00', 'UTC')) ? 95 : 10 + ($i % 5),
            'disks' => [['path' => '/', 'percent' => round(40 + $i * 0.012, 3)]],
        ]);
        $analyzer = app(PredictiveAnalyzer::class);
        $findings = fn () => Prediction::query()->where('status', 'active')->orderBy('kind')->orderBy('metric')->get()
            ->map(fn ($p) => [$p->kind, $p->metric, $p->dimension, round((float) $p->observed_value, 6), round((float) $p->baseline_value, 6), round((float) $p->slope_per_day, 6), $p->eta_at?->toIso8601String()])->all();

        $analyzer->analyzeServer($this->server->fresh());
        $raw = $findings();
        $this->assertNotEmpty($raw);
        $this->assertContains('disk_percent', array_column($raw, 1));
        $this->assertContains('cpu_percent', array_column($raw, 1));

        Prediction::query()->delete();
        app(MetricRollups::class)->rollup($this->server);
        $analyzer->analyzeServer($this->server->fresh());

        $this->assertSame($raw, $findings());
    }

    public function test_rollups_outlive_raw_samples_and_have_their_own_retention(): void
    {
        config([
            'serverscope.retention.metric_samples_days' => 7,
            'serverscope.retention.metric_rollups_hourly_days' => 30,
            'serverscope.retention.metric_rollups_daily_days' => null,
        ]);
        $this->samples($this->server, '2026-10-01 00:00:00', '2026-10-01 03:00:00');
        app(MetricRollups::class)->rollup($this->server, from: Carbon::parse('2026-10-01'));
        $this->samples($this->server, '2026-11-09 00:00:00', '2026-11-09 01:00:00');
        app(MetricRollups::class)->rollup($this->server->fresh(), from: Carbon::parse('2026-11-09'));

        $this->artisan('serverscope:prune')->assertSuccessful();

        // Raw: only the recent hour is left.
        $this->assertSame(60, MetricSample::query()->count());
        // Hourly rollups older than 30 days are gone, recent ones stay.
        $this->assertNull($this->rollup('cpu_percent', '2026-10-01 01:00:00'));
        $this->assertSame(60, $this->rollup('cpu_percent', '2026-11-09 00:00:00')->count);
        // Daily rollups are kept forever by default.
        $this->assertSame(180, $this->rollup('cpu_percent', '2026-10-01 00:00:00', '', 'day')->count);
    }

    public function test_plan_retention_for_rollups_applies_per_team_and_never_to_another_team(): void
    {
        config(['serverscope.retention.metric_rollups_hourly_days' => null]);
        $free = Team::query()->create(['name' => 'Gratis BV', 'slug' => 'gratis']);
        $pro = Team::query()->create(['name' => 'Pro BV', 'slug' => 'pro']);
        $pro->forceFill(['plan' => 'pro', 'plan_status' => 'active'])->save();

        $servers = [];

        foreach (['free' => $free, 'pro' => $pro] as $key => $team) {
            $servers[$key] = Server::forceCreate(['team_id' => $team->id, 'name' => "{$key}-01", 'hostname' => "{$key}.example.test", 'last_seen_at' => now()]);
            $this->samples($servers[$key], '2026-07-01 00:00:00', '2026-07-01 01:00:00', step: 600);
            app(MetricRollups::class)->rollup($servers[$key], from: Carbon::parse('2026-07-01'));
        }

        // The default team (operator) is exempt.
        $this->samples($this->server, '2026-07-01 00:00:00', '2026-07-01 01:00:00', step: 600);
        app(MetricRollups::class)->rollup($this->server, from: Carbon::parse('2026-07-01'));

        $this->artisan('serverscope:prune --dry-run')->assertSuccessful();
        $this->assertSame(3, MetricRollup::query()->where('metric', 'cpu_percent')->where('period', 'hour')->count());

        $this->artisan('serverscope:prune')->assertSuccessful();

        // 132 days old: beyond Free (90), within Pro (730).
        $this->assertSame(0, MetricRollup::query()->where('team_id', $free->id)->count());
        $this->assertGreaterThan(0, MetricRollup::query()->where('team_id', $pro->id)->count());
        $this->assertGreaterThan(0, MetricRollup::query()->where('server_id', $this->server->id)->count());
    }

    public function test_rollups_belong_to_the_server_team_and_are_invisible_to_other_tenants(): void
    {
        $alpha = Team::query()->create(['name' => 'Alpha BV', 'slug' => 'alpha']);
        $bravo = Team::query()->create(['name' => 'Bravo BV', 'slug' => 'bravo']);
        $a = Server::forceCreate(['team_id' => $alpha->id, 'name' => 'alpha-01', 'hostname' => 'alpha.example.test', 'last_seen_at' => now()]);
        $b = Server::forceCreate(['team_id' => $bravo->id, 'name' => 'bravo-01', 'hostname' => 'bravo.example.test', 'last_seen_at' => now()]);
        $this->samples($a, '2026-11-10 10:00:00', '2026-11-10 11:00:00', values: fn () => ['cpu_percent' => 10]);
        $this->samples($b, '2026-11-10 10:00:00', '2026-11-10 11:00:00', values: fn () => ['cpu_percent' => 90]);

        // The scheduler runs without a tenant and handles every team.
        $this->artisan('serverscope:rollup')->assertSuccessful();

        $this->assertSame([$alpha->id], MetricRollup::query()->where('server_id', $a->id)->distinct()->pluck('team_id')->all());
        $this->assertSame([$bravo->id], MetricRollup::query()->where('server_id', $b->id)->distinct()->pluck('team_id')->all());

        Tenant::run($alpha->id, function () use ($a, $b) {
            $this->assertSame([$a->id], MetricRollup::query()->distinct()->pluck('server_id')->all());
            $this->assertNull(MetricRollup::query()->where('server_id', $b->id)->first());

            $cpu = app(MetricHistory::class)->hourly($a->fresh(), now()->subDays(1), now())['cpu_percent'];
            $this->assertSame([10.0], array_values(array_unique($cpu)));
        });
    }

    public function test_the_agent_reports_cpu_count_as_server_metadata(): void
    {
        $token = str_repeat('c', 64);
        $this->server->agentCredentials()->create(['name' => 'agent', 'token_hash' => hash('sha256', $token)]);
        $payload = fn (array $extra = []) => ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 5, ...$extra];

        $this->withToken($token)->postJson('/api/agent/metrics', $payload(['cpu_count' => 8]))->assertCreated();
        $this->assertSame(8, $this->server->fresh()->cpu_count);

        // Older agents omit it: the known value stays.
        Carbon::setTestNow(now()->addMinute());
        $this->withToken($token)->postJson('/api/agent/metrics', $payload())->assertCreated();
        $this->assertSame(8, $this->server->fresh()->cpu_count);

        $this->withToken($token)->postJson('/api/agent/metrics', $payload(['cpu_count' => 0]))->assertUnprocessable()->assertJsonValidationErrors('cpu_count');
        $this->withToken($token)->postJson('/api/agent/metrics', $payload(['cpu_count' => 'many']))->assertUnprocessable();
        $this->assertSame(2, MetricSample::query()->count());
    }

    public function test_raw_retention_prunes_every_server_including_deleted_ones(): void
    {
        config(['serverscope.retention.metric_samples_days' => 7]);
        $deleted = Server::query()->create(['name' => 'oud', 'hostname' => 'oud.example.test', 'last_seen_at' => now()]);
        $this->samples($this->server, '2026-10-01 00:00:00', '2026-10-01 00:10:00');
        $this->samples($deleted, '2026-10-01 00:00:00', '2026-10-01 00:10:00');
        $this->samples($this->server, '2026-11-10 10:00:00', '2026-11-10 10:05:00');
        $deleted->delete();

        $this->artisan('serverscope:prune --dry-run')->expectsOutputToContain('metric_samples: 20 rijen ouder dan 7 dagen zouden worden verwijderd')->assertSuccessful();
        $this->artisan('serverscope:prune')->expectsOutputToContain('metric_samples: 20 rijen verwijderd')->assertSuccessful();

        $this->assertSame(5, MetricSample::query()->count());
    }
}
