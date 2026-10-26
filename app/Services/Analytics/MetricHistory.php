<?php

namespace App\Services\Analytics;

use App\Models\MetricSample;
use App\Models\Server;
use Carbon\CarbonInterface;

/**
 * Hourly means of server metrics, the unit used by anomaly detection and
 * forecasting (smooths agent noise and keeps the work bounded).
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
     * @return array<string, array<int, array{mean: float, count: int}>>
     */
    public function buckets(Server $server, CarbonInterface $from, CarbonInterface $to, int $size): array
    {
        $sums = [];
        $previous = null;

        $rows = MetricSample::query()
            ->where('server_id', $server->id)
            ->whereBetween('collected_at', [$from, $to])
            ->orderBy('collected_at')
            ->select(['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes', 'swap_total_bytes', 'swap_used_bytes', 'disks', 'network_rx_bytes', 'network_tx_bytes'])
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
