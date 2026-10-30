<?php

namespace App\Http\Controllers;

use App\Models\MetricSample;
use App\Services\ServerDiagnosis;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServerDetailController extends Controller
{
    private const MAX_CHART_POINTS = 2000;

    public function __invoke(Request $request, Server $server): View
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'in:1h,6h,24h,7d,30d'],
        ]);

        $range = $validated['range'] ?? '24h';

        $rangeOptions = [
            '1h' => [
                'label' => __('servers.ranges.1h'),
                'minutes' => 60,
            ],
            '6h' => [
                'label' => __('servers.ranges.6h'),
                'minutes' => 360,
            ],
            '24h' => [
                'label' => __('servers.ranges.24h'),
                'minutes' => 1440,
            ],
            '7d' => [
                'label' => __('servers.ranges.7d'),
                'minutes' => 10080,
            ],
            '30d' => [
                'label' => __('servers.ranges.30d'),
                'minutes' => 43200,
            ],
        ];

        $rangeStart = now()->subMinutes($rangeOptions[$range]['minutes']);

        $samplesQuery = MetricSample::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $rangeStart)
            ->where('collected_at', '<=', now());

        $sampleCount = (clone $samplesQuery)->count();

        $samples = $this->downsample(
            $samplesQuery,
            $sampleCount,
            self::MAX_CHART_POINTS,
        );

        $latestSample = $server->latestMetricSample;
        $memoryTotal = $latestSample?->memory_total_bytes ?? 0;
        $memoryUsed = $latestSample?->memory_used_bytes ?? 0;

        $memoryPercent = $memoryTotal > 0
            ? round(($memoryUsed / $memoryTotal) * 100, 1)
            : null;

        $displayStatus = $server->displayStatus();

        $disks = collect($latestSample?->disks ?? [])
            ->map(function (array $disk): array {
                $total = (int) ($disk['total_bytes'] ?? 0);
                $used = (int) ($disk['used_bytes'] ?? 0);

                return [
                    'path' => (string) ($disk['path'] ?? __('servers.unknown_cap')),
                    'total_bytes' => $total,
                    'used_bytes' => $used,
                    'available_bytes' => (int) ($disk['available_bytes'] ?? 0),
                    'percent' => $total > 0
                        ? round(min(100, max(0, ($used / $total) * 100)), 1)
                        : null,
                ];
            })
            ->values();

        $formatBytes = static function (?int $bytes): string {
            if ($bytes === null) {
                return '—';
            }

            if ($bytes < 1024) {
                return $bytes . ' B';
            }

            $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
            $value = (float) $bytes;
            $unitIndex = -1;

            do {
                $value /= 1024;
                $unitIndex++;
            } while ($value >= 1024 && $unitIndex < count($units) - 1);

            return \App\Support\Format::number($value, 1) . ' ' . $units[$unitIndex];
        };

        $formatUptime = static function (?int $seconds): string {
            if ($seconds === null) {
                return '—';
            }

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            if ($days > 0) {
                return $days.__('servers.units.day').' '.$hours.__('servers.units.hour');
            }

            if ($hours > 0) {
                return $hours.__('servers.units.hour').' '.$minutes.__('servers.units.minute');
            }

            return $minutes.__('servers.units.minute');
        };

        $chartData = [
            'labels' => $samples->map(
                fn (MetricSample $sample) => $sample->collected_at?->toIso8601String()
            )->values(),
            'cpu' => $samples->map(
                fn (MetricSample $sample) => $sample->cpu_percent
            )->values(),
            'memory' => $samples->map(function (MetricSample $sample): ?float {
                $total = $sample->memory_total_bytes;
                $used = $sample->memory_used_bytes;

                if (!$total || $used === null || $used > $total) {
                    return null;
                }

                return round(($used / $total) * 100, 1);
            })->values(),
            'load1' => $samples->map(
                fn (MetricSample $sample) => $sample->load_1
            )->values(),
            'load5' => $samples->map(
                fn (MetricSample $sample) => $sample->load_5
            )->values(),
            'load15' => $samples->map(
                fn (MetricSample $sample) => $sample->load_15
            )->values(),
        ];

        $network = [
            'rx' => $latestSample?->network_rx_bytes,
            'tx' => $latestSample?->network_tx_bytes,
        ];

        // Use the sample directly preceding the latest one: the chart
        // series may be downsampled and cannot be used for rates.
        $previousSample = $latestSample
            ? MetricSample::query()
                ->where('server_id', $server->id)
                ->where('collected_at', '<', $latestSample->collected_at)
                ->latest('collected_at')
                ->first()
            : null;

        $networkRates = [
            'rx' => null,
            'tx' => null,
        ];

        if ($latestSample && $previousSample) {
            $elapsed = $previousSample->collected_at
                ->diffInSeconds($latestSample->collected_at);

            if ($elapsed > 0) {
                foreach (['rx', 'tx'] as $direction) {
                    $field = $direction === 'rx'
                        ? 'network_rx_bytes'
                        : 'network_tx_bytes';

                    $current = $latestSample->{$field};
                    $previous = $previousSample->{$field};

                    if (
                        $current !== null
                        && $previous !== null
                        && $current >= $previous
                    ) {
                        $networkRates[$direction] = ($current - $previous) / $elapsed;
                    }
                }
            }
        }

        $credentials = $request->user()->can('admin')
            ? $server->agentCredentials()->latest('id')->get()
            : collect();

        $recentAlerts = $server->alerts()
            ->with('alertRule:id,name')
            ->latest('triggered_at')
            ->limit(5)
            ->get();

        $diagnosis = app(ServerDiagnosis::class);

        return view('servers.show', [
            'server' => $server,
            'diagnosis' => $diagnosis->diagnose($server),
            'quality' => $diagnosis->dataQuality($server),
            'services' => $server->serviceChecks()->orderBy('name')->get(),
            'history' => $this->history($samples),
            'predictions' => \App\Models\Prediction::query()->forResource($server)->current()->orderByDesc('severity_rank')->get(),
            'credentials' => $credentials,
            'agentUpdate' => app(\App\Services\AgentUpdates::class)->summary($server),
            'recentAlerts' => $recentAlerts,
            'displayStatus' => $displayStatus,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'rangeStart' => $rangeStart,
            'samples' => $samples,
            'sampleCount' => $sampleCount,
            'latestSample' => $latestSample,
            'memoryPercent' => $memoryPercent,
            'memoryTotal' => $memoryTotal,
            'memoryUsed' => $memoryUsed,
            'disks' => $disks,
            'network' => $network,
            'networkRates' => $networkRates,
            'chartData' => $chartData,
            'formatBytes' => $formatBytes,
            'formatUptime' => $formatUptime,
        ]);
    }

    /**
     * Extended history for server-side charts: swap usage and rates
     * derived from cumulative counters (network, disk I/O, errors).
     * A counter that goes down (reboot, wrap) yields a gap, not a spike.
     *
     * @param  Collection<int, MetricSample>  $samples
     * @return array<string, array<int, array{label: string, value: ?float, flag: bool}>>
     */
    private function history(Collection $samples): array
    {
        $series = ['swap' => [], 'rx' => [], 'tx' => [], 'disk_read' => [], 'disk_write' => [], 'errors' => [], 'processes' => []];
        $previous = null;

        foreach ($samples as $sample) {
            $label = \App\Support\Time::format($sample->collected_at, 'd-m H:i');
            $swap = $sample->swap_total_bytes > 0 && $sample->swap_used_bytes !== null ? round($sample->swap_used_bytes / $sample->swap_total_bytes * 100, 1) : null;
            $series['swap'][] = ['label' => $label, 'value' => $swap, 'flag' => false];
            $series['processes'][] = ['label' => $label, 'value' => $sample->process_count !== null ? (float) $sample->process_count : null, 'flag' => false];

            $elapsed = $previous ? $previous->collected_at->diffInSeconds($sample->collected_at) : 0;
            $rate = function (string $field, float $divisor = 1) use ($sample, $previous, $elapsed): ?float {
                if (! $previous || $elapsed <= 0 || $sample->{$field} === null || $previous->{$field} === null || $sample->{$field} < $previous->{$field}) {
                    return null;
                }

                return round(($sample->{$field} - $previous->{$field}) / $elapsed / $divisor, 2);
            };

            $series['rx'][] = ['label' => $label, 'value' => $rate('network_rx_bytes', 1024), 'flag' => false];
            $series['tx'][] = ['label' => $label, 'value' => $rate('network_tx_bytes', 1024), 'flag' => false];
            $series['disk_read'][] = ['label' => $label, 'value' => $rate('disk_read_bytes', 1024), 'flag' => false];
            $series['disk_write'][] = ['label' => $label, 'value' => $rate('disk_write_bytes', 1024), 'flag' => false];

            $in = $rate('network_errors_in');
            $out = $rate('network_errors_out');
            $errors = $in === null && $out === null ? null : round(($in ?? 0) + ($out ?? 0), 3);
            $series['errors'][] = ['label' => $label, 'value' => $errors !== null ? $errors * 60 : null, 'flag' => $errors !== null && $errors > 0];

            $previous = $sample;
        }

        return $series;
    }

    /**
     * Spread at most $maxPoints samples evenly across the whole range,
     * always keeping the most recent sample.
     *
     * @return Collection<int, MetricSample>
     */
    private function downsample(
        Builder $query,
        int $total,
        int $maxPoints,
    ): Collection {
        $step = max(1, (int) ceil($total / $maxPoints));
        $samples = collect();
        $last = null;
        $index = 0;

        foreach ($query->orderBy('collected_at')->lazy(1000) as $sample) {
            if ($index % $step === 0) {
                $samples->push($sample);
            }

            $last = $sample;
            $index++;
        }

        if ($last !== null && $samples->last() !== $last) {
            $samples->push($last);
        }

        return $samples;
    }
}
