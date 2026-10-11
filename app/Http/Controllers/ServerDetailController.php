<?php

namespace App\Http\Controllers;

use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ServerDetailController extends Controller
{
    public function __invoke(Request $request, Server $server): View
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'in:1h,6h,24h,7d,30d'],
        ]);

        $range = $validated['range'] ?? '24h';

        $rangeOptions = [
            '1h' => [
                'label' => 'Laatste uur',
                'minutes' => 60,
            ],
            '6h' => [
                'label' => 'Laatste 6 uur',
                'minutes' => 360,
            ],
            '24h' => [
                'label' => 'Laatste 24 uur',
                'minutes' => 1440,
            ],
            '7d' => [
                'label' => 'Laatste 7 dagen',
                'minutes' => 10080,
            ],
            '30d' => [
                'label' => 'Laatste 30 dagen',
                'minutes' => 43200,
            ],
        ];

        $rangeStart = now()->subMinutes($rangeOptions[$range]['minutes']);

        $samples = MetricSample::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $rangeStart)
            ->where('collected_at', '<=', now())
            ->orderBy('collected_at')
            ->limit(2000)
            ->get();

        $latestSample = $server->latestMetricSample;
        $memoryTotal = $latestSample?->memory_total_bytes ?? 0;
        $memoryUsed = $latestSample?->memory_used_bytes ?? 0;

        $memoryPercent = $memoryTotal > 0
            ? round(($memoryUsed / $memoryTotal) * 100, 1)
            : null;

        $displayStatus = match (true) {
            $server->last_seen_at === null => 'pending',
            $server->last_seen_at->lt(now()->subMinutes(2)) => 'offline',
            default => 'online',
        };

        $disks = collect($latestSample?->disks ?? [])
            ->map(function (array $disk): array {
                $total = (int) ($disk['total_bytes'] ?? 0);
                $used = (int) ($disk['used_bytes'] ?? 0);

                return [
                    'path' => (string) ($disk['path'] ?? 'Onbekend'),
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

            return number_format($value, 1, ',', '.') . ' ' . $units[$unitIndex];
        };

        $formatUptime = static function (?int $seconds): string {
            if ($seconds === null) {
                return '—';
            }

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            if ($days > 0) {
                return "{$days}d {$hours}u";
            }

            if ($hours > 0) {
                return "{$hours}u {$minutes}m";
            }

            return "{$minutes}m";
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

        $previousSample = $samples->count() >= 2
            ? $samples->get($samples->count() - 2)
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

        return view('servers.show', [
            'server' => $server,
            'displayStatus' => $displayStatus,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'rangeStart' => $rangeStart,
            'samples' => $samples,
            'sampleCount' => $samples->count(),
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
}
