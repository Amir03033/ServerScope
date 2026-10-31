<?php

namespace App\Services\Analytics;

use App\Http\Controllers\Api\MetricSampleController;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Incremental hourly and daily aggregates of raw metric samples.
 *
 * - Only closed hours are aggregated: an hour is closed once no sample
 *   for it can still be accepted (the ingest window plus a minute).
 * - Every hour is recomputed from raw samples and written with an upsert
 *   on the unique key, so running twice, retrying a failed job or two
 *   workers racing produce the same rows (no duplicates, no double
 *   counting).
 * - A bucket is never replaced by one built from fewer samples: after raw
 *   samples were pruned, a recomputation cannot shrink the history.
 * - Daily rows are merged from the hourly rows of that day (count, sum,
 *   min and max merge exactly).
 * - servers.metrics_rolled_up_until advances per chunk of hours, so an
 *   interrupted run resumes where it stopped.
 *
 * Rates (network, disk I/O, errors) are derived from cumulative counters
 * between consecutive samples at most 10 minutes apart; a counter that
 * goes down (reboot, wrap) yields no value, exactly as on the charts.
 */
class MetricRollups
{
    /** Hours aggregated per transaction. */
    public const CHUNK_HOURS = 24;

    /** Longest gap between two samples for which a rate is calculated. */
    public const MAX_RATE_GAP_SECONDS = 600;

    /**
     * The metrics MetricHistory (anomaly detection and forecasts) reads,
     * as stored in rollups. Other rollup metrics serve charts and reports.
     */
    public const HISTORY_METRICS = ['cpu_percent', 'load_1', 'memory_percent', 'swap_percent', 'disk_percent', 'network_rx', 'network_tx'];

    /** The newest hour boundary that is closed at $now. */
    public static function closedUntil(?CarbonInterface $now = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        return self::floorHour($now->subMinutes(MetricSampleController::MAX_SAMPLE_AGE_MINUTES + 1));
    }

    public static function floorHour(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC(intdiv($time->getTimestamp(), 3600) * 3600);
    }

    public static function floorDay(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC(intdiv($time->getTimestamp(), 86400) * 86400);
    }

    /**
     * Aggregate every closed hour from the server's watermark (or $from)
     * up to the closed boundary.
     *
     * @return array{hours: int, rows: int, until: ?string}
     */
    public function rollup(Server $server, ?CarbonInterface $from = null, ?CarbonInterface $now = null): array
    {
        $until = self::closedUntil($now);
        $start = $this->startFor($server, $from, $until);
        $result = ['hours' => 0, 'rows' => 0, 'until' => $server->metrics_rolled_up_until?->toIso8601String()];

        if ($start === null || $start->gte($until)) {
            return $result;
        }

        for ($chunkStart = $start; $chunkStart->lt($until); $chunkStart = $chunkStart->addHours(self::CHUNK_HOURS)) {
            $chunkEnd = $chunkStart->addHours(self::CHUNK_HOURS)->min($until);

            $result['rows'] += DB::transaction(function () use ($server, $chunkStart, $chunkEnd): int {
                $rows = $this->rollupHours($server, $chunkStart, $chunkEnd);
                $rows += $this->rollupDays($server, self::floorDay($chunkStart), $chunkEnd);

                // Never move the watermark back (an explicit --from
                // recomputation of old hours leaves it where it was).
                DB::table('servers')->where('id', $server->id)
                    ->where(fn ($q) => $q->whereNull('metrics_rolled_up_until')->orWhere('metrics_rolled_up_until', '<', $chunkEnd))
                    ->update(['metrics_rolled_up_until' => $chunkEnd]);

                return $rows;
            });

            $result['hours'] += (int) ($chunkStart->diffInHours($chunkEnd));
        }

        $server->refresh();
        $result['until'] = $server->metrics_rolled_up_until?->toIso8601String();

        return $result;
    }

    /**
     * Where a run starts: an explicit $from (backfill/recompute), else the
     * watermark, else the first sample but no further back than the
     * initial backfill limit (older history: use --from).
     */
    private function startFor(Server $server, ?CarbonInterface $from, CarbonImmutable $until): ?CarbonImmutable
    {
        if ($from !== null) {
            return self::floorHour($from);
        }

        if ($server->metrics_rolled_up_until !== null) {
            return self::floorHour($server->metrics_rolled_up_until);
        }

        $first = MetricSample::query()->where('server_id', $server->id)->min('collected_at');

        if ($first === null) {
            return null;
        }

        $limit = $until->subDays(max(1, (int) config('serverscope.rollups.initial_backfill_days', 35)));

        return self::floorHour(CarbonImmutable::parse($first, 'UTC'))->max($limit);
    }

    /** Recompute and upsert the hourly rows of [$start, $end). */
    public function rollupHours(Server $server, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $aggregates = [];
        $previous = MetricSample::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '<', $start)
            ->where('collected_at', '>=', $start->subSeconds(self::MAX_RATE_GAP_SECONDS))
            ->orderByDesc('collected_at')
            ->first(self::COLUMNS);

        $samples = MetricSample::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $start)
            ->where('collected_at', '<', $end)
            ->orderBy('collected_at')
            ->select(self::COLUMNS)
            ->lazy(2000);

        foreach ($samples as $sample) {
            $bucket = intdiv($sample->collected_at->getTimestamp(), 3600) * 3600;

            foreach (self::values($sample, $previous) as [$metric, $dimension, $value]) {
                $a = &$aggregates[$bucket][$metric][$dimension];

                if ($a === null) {
                    $a = ['count' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
                }

                $a['count']++;
                $a['sum'] += $value;
                $a['min'] = min($a['min'], $value);
                $a['max'] = max($a['max'], $value);
                unset($a);
            }

            $previous = $sample;
        }

        return $this->write($server, MetricRollup::HOUR, $start, $end, $aggregates);
    }

    /** Recompute and upsert the daily rows of the days in [$start, $end) from hourly rows. */
    public function rollupDays(Server $server, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $dayEnd = self::floorDay($end->subSecond())->addDay();
        $aggregates = [];

        $hours = DB::table('metric_rollups')
            ->where('server_id', $server->id)
            ->where('period', MetricRollup::HOUR)
            ->where('bucket_start', '>=', $start)
            ->where('bucket_start', '<', $dayEnd)
            ->orderBy('bucket_start')
            ->get(['bucket_start', 'metric', 'dimension', 'count', 'sum', 'min', 'max']);

        foreach ($hours as $row) {
            $day = intdiv(CarbonImmutable::parse($row->bucket_start, 'UTC')->getTimestamp(), 86400) * 86400;
            $a = &$aggregates[$day][$row->metric][$row->dimension];

            if ($a === null) {
                $a = ['count' => 0, 'sum' => 0.0, 'min' => (float) $row->min, 'max' => (float) $row->max];
            }

            $a['count'] += (int) $row->count;
            $a['sum'] += (float) $row->sum;
            $a['min'] = min($a['min'], (float) $row->min);
            $a['max'] = max($a['max'], (float) $row->max);
            unset($a);
        }

        return $this->write($server, MetricRollup::DAY, $start, $dayEnd, $aggregates);
    }

    /**
     * Upsert aggregates, skipping buckets that already hold more samples
     * (raw data pruned since they were written).
     *
     * @param  array<int, array<string, array<string, array{count: int, sum: float, min: float, max: float}>>>  $aggregates
     */
    private function write(Server $server, string $period, CarbonImmutable $start, CarbonImmutable $end, array $aggregates): int
    {
        if ($aggregates === []) {
            return 0;
        }

        $existing = [];

        foreach (DB::table('metric_rollups')
            ->where('server_id', $server->id)
            ->where('period', $period)
            ->where('bucket_start', '>=', $start)
            ->where('bucket_start', '<', $end)
            ->get(['bucket_start', 'metric', 'dimension', 'count']) as $row) {
            $existing[CarbonImmutable::parse($row->bucket_start, 'UTC')->getTimestamp()."\0{$row->metric}\0{$row->dimension}"] = (int) $row->count;
        }

        $now = now();
        $rows = [];

        foreach ($aggregates as $bucket => $metrics) {
            foreach ($metrics as $metric => $dimensions) {
                foreach ($dimensions as $dimension => $a) {
                    if (($existing["{$bucket}\0{$metric}\0{$dimension}"] ?? -1) > $a['count']) {
                        continue;
                    }

                    $rows[] = [
                        'server_id' => $server->id,
                        'team_id' => $server->team_id,
                        'period' => $period,
                        'bucket_start' => CarbonImmutable::createFromTimestampUTC($bucket),
                        'metric' => $metric,
                        'dimension' => (string) $dimension,
                        'count' => $a['count'],
                        'sum' => $a['sum'],
                        'min' => $a['min'],
                        'max' => $a['max'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('metric_rollups')->upsert(
                $chunk,
                ['server_id', 'period', 'bucket_start', 'metric', 'dimension'],
                ['count', 'sum', 'min', 'max', 'updated_at'],
            );
        }

        return count($rows);
    }

    /** Raw columns needed to aggregate a sample. */
    private const COLUMNS = [
        'id', 'server_id', 'collected_at', 'cpu_percent', 'load_1', 'load_5', 'load_15',
        'memory_total_bytes', 'memory_used_bytes', 'swap_total_bytes', 'swap_used_bytes', 'disks',
        'network_rx_bytes', 'network_tx_bytes', 'disk_read_bytes', 'disk_write_bytes',
        'network_errors_in', 'network_errors_out', 'process_count',
    ];

    /**
     * The rolled-up values of one sample as [metric, dimension, value].
     * Percentages and rates use the same formulas as MetricHistory and
     * the server charts.
     *
     * @return array<int, array{0: string, 1: string, 2: float}>
     */
    public static function values(MetricSample $sample, ?MetricSample $previous): array
    {
        $values = [];
        $add = function (string $metric, $value, string $dimension = '') use (&$values): void {
            if ($value !== null && is_numeric($value) && is_finite((float) $value)) {
                $values[] = [$metric, $dimension, (float) $value];
            }
        };

        $add('cpu_percent', $sample->cpu_percent);
        $add('load_1', $sample->load_1);
        $add('load_5', $sample->load_5);
        $add('load_15', $sample->load_15);
        $add('memory_percent', $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100 : null);
        $add('swap_percent', $sample->swap_total_bytes > 0 && $sample->swap_used_bytes !== null ? $sample->swap_used_bytes / $sample->swap_total_bytes * 100 : null);
        $add('process_count', $sample->process_count);

        foreach ((array) $sample->disks as $disk) {
            if (is_array($disk) && isset($disk['path'], $disk['percent']) && is_numeric($disk['percent'])) {
                $add('disk_percent', (float) $disk['percent'], mb_substr((string) $disk['path'], 0, 255));
            }
        }

        $elapsed = $previous !== null ? $previous->collected_at->diffInSeconds($sample->collected_at) : 0;

        if ($elapsed > 0 && $elapsed <= self::MAX_RATE_GAP_SECONDS) {
            $rate = function (string $field) use ($sample, $previous, $elapsed): ?float {
                $now = $sample->{$field};
                $before = $previous->{$field};

                return $now !== null && $before !== null && $now >= $before ? ($now - $before) / $elapsed : null;
            };

            // KB/s, as in MetricHistory and on the server page.
            foreach (['network_rx' => 'network_rx_bytes', 'network_tx' => 'network_tx_bytes', 'disk_read' => 'disk_read_bytes', 'disk_write' => 'disk_write_bytes'] as $metric => $field) {
                $value = $rate($field);
                $add($metric, $value !== null ? $value / 1024 : null);
            }

            // Errors per second, in and out combined.
            $in = $rate('network_errors_in');
            $out = $rate('network_errors_out');
            $add('network_errors', $in === null && $out === null ? null : ($in ?? 0) + ($out ?? 0));
        }

        return $values;
    }
}
