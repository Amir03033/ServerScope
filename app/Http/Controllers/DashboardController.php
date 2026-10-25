<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\MetricSample;
use App\Models\Server;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $now = now();

        $hours = (int) request()->query('range', 24);
        if (! in_array($hours, [1, 6, 24, 168], true)) {
            $hours = 24;
        }

        $chartStart = $now->copy()->subHours($hours);

        $servers = Server::query()
            ->with('latestMetricSample')
            ->orderBy('name')
            ->get()
            ->map(function (Server $server) use ($now): Server {
                $server->display_status = $server->displayStatus($now);

                $sample = $server->latestMetricSample;

                $total = (float) ($sample?->memory_total_bytes ?? 0);
                $used = (float) ($sample?->memory_used_bytes ?? 0);

                $server->memory_percent = $total > 0
                    ? round(($used / $total) * 100, 1)
                    : null;

                return $server;
            });

        $selectedServer = $servers
            ->filter(fn (Server $server) => $server->latestMetricSample !== null)
            ->sortByDesc(fn (Server $server) => $server->last_seen_at?->timestamp ?? 0)
            ->first();

        $samples = $selectedServer
            ? MetricSample::query()
                ->where('server_id', $selectedServer->id)
                ->whereBetween('collected_at', [$chartStart, $now])
                ->orderBy('collected_at')
                ->get()
            : collect();

        // Voor de grafieken maximaal ~300 punten (anders te zwaar bij 7 dagen).
        $step = max(1, (int) ceil($samples->count() / 300));
        $chartSamples = $samples
            ->values()
            ->filter(fn ($sample, int $index) => $index % $step === 0)
            ->values();

        $latestSample = $selectedServer?->latestMetricSample;

        $cpuValues = $samples
            ->pluck('cpu_percent')
            ->filter(fn ($value) => $value !== null);

        $memoryValues = $samples
            ->map(function (MetricSample $sample): ?float {
                $total = (float) ($sample->memory_total_bytes ?? 0);
                $used = (float) ($sample->memory_used_bytes ?? 0);

                return $total > 0
                    ? round(($used / $total) * 100, 1)
                    : null;
            })
            ->filter(fn ($value) => $value !== null);

        $latestMemoryTotal = (float) ($latestSample?->memory_total_bytes ?? 0);
        $latestMemoryUsed = (float) ($latestSample?->memory_used_bytes ?? 0);
        $latestMemoryAvailable = (float) ($latestSample?->memory_available_bytes ?? 0);

        $memoryPercent = $latestMemoryTotal > 0
            ? round(($latestMemoryUsed / $latestMemoryTotal) * 100, 1)
            : null;

        $disks = collect($latestSample?->disks ?? [])
            ->map(function (array $disk): array {
                $total = (float) (
                    $disk['total_bytes']
                    ?? $disk['total']
                    ?? 0
                );

                $used = (float) (
                    $disk['used_bytes']
                    ?? $disk['used']
                    ?? 0
                );

                $available = (float) (
                    $disk['available_bytes']
                    ?? $disk['free_bytes']
                    ?? $disk['available']
                    ?? $disk['free']
                    ?? max(0, $total - $used)
                );

                return [
                    'mountpoint' => $disk['mountpoint']
                        ?? $disk['mount']
                            ?? $disk['path']
                            ?? 'Onbekend',
                    'total_bytes' => $total,
                    'used_bytes' => $used,
                    'available_bytes' => $available,
                    'used_percent' => $total > 0
                        ? round(($used / $total) * 100, 1)
                        : null,
                ];
            });

        $latestTwoSamples = $samples->reverse()->take(2)->values();

        $downloadRate = null;
        $uploadRate = null;

        if ($latestTwoSamples->count() === 2) {
            $older = $latestTwoSamples[1];
            $newer = $latestTwoSamples[0];

            $elapsed = $older->collected_at?->diffInSeconds(
                $newer->collected_at,
                true
            ) ?? 0;

            if ($elapsed > 0) {
                if (
                    $older->network_rx_bytes !== null
                    && $newer->network_rx_bytes !== null
                    && $newer->network_rx_bytes >= $older->network_rx_bytes
                ) {
                    $downloadRate = (
                            $newer->network_rx_bytes - $older->network_rx_bytes
                        ) / $elapsed;
                }

                if (
                    $older->network_tx_bytes !== null
                    && $newer->network_tx_bytes !== null
                    && $newer->network_tx_bytes >= $older->network_tx_bytes
                ) {
                    $uploadRate = (
                            $newer->network_tx_bytes - $older->network_tx_bytes
                        ) / $elapsed;
                }
            }
        }

        // Netwerksnelheid per interval, voor de sparklines.
        $networkSeries = ['rx' => [], 'tx' => []];

        for ($i = 1; $i < $chartSamples->count(); $i++) {
            $a = $chartSamples[$i - 1];
            $b = $chartSamples[$i];

            $secs = $a->collected_at?->diffInSeconds($b->collected_at, true) ?? 0;

            $networkSeries['rx'][] = ($secs > 0
                && $a->network_rx_bytes !== null && $b->network_rx_bytes !== null
                && $b->network_rx_bytes >= $a->network_rx_bytes)
                ? ($b->network_rx_bytes - $a->network_rx_bytes) / $secs
                : null;

            $networkSeries['tx'][] = ($secs > 0
                && $a->network_tx_bytes !== null && $b->network_tx_bytes !== null
                && $b->network_tx_bytes >= $a->network_tx_bytes)
                ? ($b->network_tx_bytes - $a->network_tx_bytes) / $secs
                : null;
        }

        $activeAlertCount = Alert::query()
            ->where('status', 'active')
            ->count();

        $chartData = [
            'labels' => $chartSamples->map(
                fn (MetricSample $sample) =>
                $sample->collected_at?->toIso8601String()
            )->values(),

            'cpu' => $chartSamples->map(
                fn (MetricSample $sample) =>
                $sample->cpu_percent !== null
                    ? round((float) $sample->cpu_percent, 1)
                    : null
            )->values(),

            'memory' => $chartSamples->map(
                function (MetricSample $sample): ?float {
                    $total = (float) ($sample->memory_total_bytes ?? 0);
                    $used = (float) ($sample->memory_used_bytes ?? 0);

                    return $total > 0
                        ? round(($used / $total) * 100, 1)
                        : null;
                }
            )->values(),

            'load1' => $chartSamples->map(
                fn (MetricSample $sample) =>
                $sample->load_1 !== null
                    ? (float) $sample->load_1
                    : null
            )->values(),

            'load5' => $chartSamples->map(
                fn (MetricSample $sample) =>
                $sample->load_5 !== null
                    ? (float) $sample->load_5
                    : null
            )->values(),

            'load15' => $chartSamples->map(
                fn (MetricSample $sample) =>
                $sample->load_15 !== null
                    ? (float) $sample->load_15
                    : null
            )->values(),
        ];

        $recentSamples = $selectedServer
            ? MetricSample::query()
                ->where('server_id', $selectedServer->id)
                ->latest('collected_at')
                ->limit(10)
                ->get()
            : collect();

        return view('dashboard', [
            'overview' => app(\App\Services\Reports\DashboardOverview::class)->data(),
            'servers' => $servers,
            'serverCount' => $servers->count(),
            'onlineCount' => $servers->where('display_status', 'online')->count(),
            'offlineCount' => $servers->where('display_status', 'offline')->count(),
            'pendingCount' => $servers->where('display_status', 'pending')->count(),

            'activeAlertCount' => $activeAlertCount,
            'selectedServer' => $selectedServer,
            'latestSample' => $latestSample,
            'recentSamples' => $recentSamples,

            'cpuAverage' => $cpuValues->isNotEmpty()
                ? round((float) $cpuValues->avg(), 1)
                : null,

            'memoryAverage' => $memoryValues->isNotEmpty()
                ? round((float) $memoryValues->avg(), 1)
                : null,

            'memoryPercent' => $memoryPercent,
            'memoryTotalBytes' => $latestMemoryTotal,
            'memoryUsedBytes' => $latestMemoryUsed,
            'memoryAvailableBytes' => $latestMemoryAvailable,

            'disks' => $disks,
            'downloadRate' => $downloadRate,
            'uploadRate' => $uploadRate,
            'networkSeries' => $networkSeries,

            'hours' => $hours,
            'chartData' => $chartData,
            'chartStart' => $chartStart,
            'chartEnd' => $now,
        ]);
    }
}
