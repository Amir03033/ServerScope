<?php

namespace App\Services\Analytics;

use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hourly means of server metrics, the unit used by anomaly detection and
 * forecasting (smooths agent noise and keeps the work bounded). Whole
 * aggregated hours are read from metric_rollups (see MetricRollups).
 */
class MetricHistory
{
    /**
     * @return array<string, array<int, float>> metric (or "disk_percent:/mount") => [hour timestamp => mean]
     */
    public function hourly(Server $server, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(fn (array $buckets) => array_map(fn (array $b) => $b['mean'], $buckets), $this->buckets($server, $from, $to, 3600));
    }

    /**
     * Mean and sample count per metric over the whole period.
     *
     * @return array<string, array{mean: float, count: int}>
     */
    public function summary(Server $server, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(fn (array $buckets) => [
            'mean' => array_sum(array_map(fn ($b) => $b['mean'] * $b['count'], $buckets)) / max(1, array_sum(array_column($buckets, 'count'))),
            'count' => array_sum(array_column($buckets, 'count')),
        ], $this->buckets($server, $from, $to, 3600));
    }

    /**
     * Hourly buckets come from metric_rollups for every whole hour that
     * has been aggregated (before servers.metrics_rolled_up_until and not
     * before the oldest rollup still kept), and from raw samples for the
     * partial first hour and the hours not aggregated yet. The result is
     * the same as reading only raw samples, except that a rate at the
     * start of an aggregated hour may use the sample just before it.
     *
     * @return array<string, array<int, array{mean: float, count: int}>>
     */
    public function buckets(Server $server, CarbonInterface $from, CarbonInterface $to, int $size): array
    {
        $watermark = $server->metrics_rolled_up_until;

        if ($size !== 3600 || $watermark === null || ! config('serverscope.rollups.read', true)) {
            return $this->rawBuckets($server, $from, $to, $size);
        }

        $oldest = MetricRollup::query()->withoutGlobalScopes()
            ->where('server_id', $server->id)->where('period', MetricRollup::HOUR)->min('bucket_start');

        if ($oldest === null) {
            return $this->rawBuckets($server, $from, $to, $size);
        }

        $start = MetricRollups::floorHour($from);
        $start = $start->lt($from) ? $start->addHour() : $start;
        $start = $start->max(CarbonImmutable::parse($oldest, 'UTC'));
        $end = MetricRollups::floorHour($to)->min(MetricRollups::floorHour($watermark));

        if ($start->gte($end)) {
            return $this->rawBuckets($server, $from, $to, $size);
        }

        $result = $this->rawBuckets($server, $from, $start, $size, endExclusive: true);

        $rollups = MetricRollup::query()->withoutGlobalScopes()
            ->where('server_id', $server->id)
            ->where('period', MetricRollup::HOUR)
            ->where('bucket_start', '>=', $start)
            ->where('bucket_start', '<', $end)
            ->whereIn('metric', MetricRollups::HISTORY_METRICS)
            ->toBase()
            ->get(['bucket_start', 'metric', 'dimension', 'count', 'sum']);

        foreach ($rollups as $row) {
            if ((int) $row->count <= 0) {
                continue;
            }

            $key = $row->metric === 'disk_percent' ? 'disk_percent:'.$row->dimension : $row->metric;
            $hour = CarbonImmutable::parse($row->bucket_start, 'UTC')->getTimestamp();
            $result[$key][$hour] = ['mean' => (float) $row->sum / (int) $row->count, 'count' => (int) $row->count];
        }

        // The first raw sample after the aggregated hours continues from
        // the last one inside them (rates), as in a raw-only read.
        $seed = MetricSample::query()->where('server_id', $server->id)
            ->where('collected_at', '>=', $start)->where('collected_at', '<', $end)
            ->orderByDesc('collected_at')->first(self::COLUMNS);

        foreach ($this->rawBuckets($server, $end, $to, $size, seed: $seed) as $metric => $hours) {
            foreach ($hours as $hour => $bucket) {
                $result[$metric][$hour] = $bucket;
            }
        }

        foreach ($result as &$hours) {
            ksort($hours);
        }

        return $result;
    }

    private const COLUMNS = ['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes', 'swap_total_bytes', 'swap_used_bytes', 'disks', 'network_rx_bytes', 'network_tx_bytes'];

    /**
     * Buckets computed from raw samples in [$from, $to] (or [$from, $to)).
     *
     * @return array<string, array<int, array{mean: float, count: int}>>
     */
    private function rawBuckets(Server $server, CarbonInterface $from, CarbonInterface $to, int $size, bool $endExclusive = false, ?MetricSample $seed = null): array
    {
        $sums = [];
        $previous = $seed;

        $rows = MetricSample::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $from)
            ->where('collected_at', $endExclusive ? '<' : '<=', $to)
            ->orderBy('collected_at')
            ->select(self::COLUMNS)
            ->lazy(2000);

        foreach ($rows as $sample) {
            $hour = intdiv($sample->collected_at->getTimestamp(), $size) * $size;
            $values = [
                'cpu_percent' => $sample->cpu_percent,
                'load_1' => $sample->load_1,
                'memory_percent' => $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100 : null,
                'swap_percent' => $sample->swap_total_bytes > 0 && $sample->swap_used_bytes !== null ? $sample->swap_used_bytes / $sample->swap_total_bytes * 100 : null,
            ];

            foreach ((array) $sample->disks as $disk) {
                if (is_array($disk) && isset($disk['path'], $disk['percent']) && is_numeric($disk['percent'])) {
                    $values['disk_percent:'.$disk['path']] = (float) $disk['percent'];
                }
            }

            if ($previous !== null) {
                $elapsed = $previous->collected_at->diffInSeconds($sample->collected_at);

                foreach (['rx' => 'network_rx_bytes', 'tx' => 'network_tx_bytes'] as $key => $field) {
                    if ($elapsed > 0 && $elapsed <= 600 && $sample->{$field} !== null && $previous->{$field} !== null && $sample->{$field} >= $previous->{$field}) {
                        $values['network_'.$key] = ($sample->{$field} - $previous->{$field}) / $elapsed / 1024;
                    }
                }
            }

            foreach ($values as $metric => $value) {
                if ($value === null) {
                    continue;
                }

                $sums[$metric][$hour][0] = ($sums[$metric][$hour][0] ?? 0) + (float) $value;
                $sums[$metric][$hour][1] = ($sums[$metric][$hour][1] ?? 0) + 1;
            }

            $previous = $sample;
        }

        $result = [];

        foreach ($sums as $metric => $hours) {
            ksort($hours);

            foreach ($hours as $hour => [$sum, $count]) {
                $result[$metric][$hour] = ['mean' => $sum / $count, 'count' => $count];
            }
        }

        return $result;
    }
}
