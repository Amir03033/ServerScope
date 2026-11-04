<?php

namespace App\Services\Demo;

use Carbon\CarbonInterface;

/**
 * How each demo server behaves over time, so the seeder (history) and the
 * minute tick (now) produce the same believable lines: a web server with a
 * day rhythm, a calm database whose data disk fills up (a capacity
 * forecast), a worker, a mail server and an edge server that went offline.
 */
class DemoProfiles
{
    public const SERVERS = [
        'web-01' => ['hostname' => 'web-01.acme.example', 'os' => 'Ubuntu 24.04 LTS', 'env' => 'production', 'cpus' => 4, 'memory' => 8, 'services' => ['nginx' => 'running', 'php8.3-fpm' => 'running']],
        'db-01' => ['hostname' => 'db-01.acme.example', 'os' => 'Ubuntu 24.04 LTS', 'env' => 'production', 'cpus' => 8, 'memory' => 32, 'services' => ['mysql' => 'running', 'redis-server' => 'running']],
        'worker-01' => ['hostname' => 'worker-01.acme.example', 'os' => 'Debian 12', 'env' => 'production', 'cpus' => 2, 'memory' => 4, 'services' => ['supervisor' => 'running', 'horizon' => 'stopped']],
        'mail-01' => ['hostname' => 'mail-01.acme.example', 'os' => 'Debian 12', 'env' => 'production', 'cpus' => 2, 'memory' => 4, 'services' => ['postfix' => 'running']],
        'edge-01' => ['hostname' => 'edge-01.acme.example', 'os' => 'Ubuntu 22.04 LTS', 'env' => 'staging', 'cpus' => 2, 'memory' => 2, 'services' => []],
    ];

    /** The server that stopped reporting this long before seeding. */
    public const OFFLINE = 'edge-01';

    public const OFFLINE_FOR_MINUTES = 190;

    /** web-01's CPU overload, minutes before seeding (start, end). */
    public const CPU_SPIKE = [26 * 60, 26 * 60 - 14];

    /**
     * One metric sample for a server at a moment.
     *
     * @param  CarbonInterface  $anchor  when the demo was seeded (the disk forecast is anchored there)
     * @return array<string, mixed>
     */
    public static function sample(string $name, CarbonInterface $at, CarbonInterface $anchor): array
    {
        $profile = self::SERVERS[$name];
        $hour = (int) $at->format('G') + (int) $at->format('i') / 60;
        $seed = crc32($name.$at->format('YmdHi'));
        $noise = fn (float $spread) => (($seed % 1000) / 1000 - 0.5) * 2 * $spread;
        $minutesBefore = ($anchor->getTimestamp() - $at->getTimestamp()) / 60;
        // Busy in the evening, quiet at night (local business hours).
        $day = sin(($hour - 9) / 24 * 2 * M_PI);

        $cpu = match ($name) {
            'web-01' => 34 + 22 * $day + $noise(6),
            'db-01' => 14 + 5 * $day + $noise(3),
            'worker-01' => 22 + ($at->minute < 10 ? 35 : 0) + $noise(5),
            'mail-01' => 6 + $noise(2),
            default => 9 + $noise(3),
        };

        if ($name === 'web-01' && $minutesBefore <= self::CPU_SPIKE[0] && $minutesBefore >= self::CPU_SPIKE[1]) {
            $cpu = 96 + $noise(2);
        }

        $memoryTotal = $profile['memory'] * 1024 ** 3;
        $memoryUsed = (int) ($memoryTotal * match ($name) {
            'db-01' => 0.71, 'web-01' => 0.56 + 0.06 * $day, 'worker-01' => 0.48, default => 0.31
        });
        $seconds = $at->getTimestamp() - 1_700_000_000;
        $rate = ['web-01' => 900_000, 'db-01' => 400_000, 'worker-01' => 120_000, 'mail-01' => 40_000][$name] ?? 20_000;

        return [
            'cpu_percent' => round(max(1, min(100, $cpu)), 1),
            'load_1' => round(max(0.02, $cpu / 100 * $profile['cpus']), 2),
            'load_5' => round(max(0.02, $cpu / 105 * $profile['cpus']), 2),
            'load_15' => round(max(0.02, $cpu / 110 * $profile['cpus']), 2),
            'memory_total_bytes' => $memoryTotal,
            'memory_used_bytes' => $memoryUsed,
            'memory_available_bytes' => $memoryTotal - $memoryUsed,
            'swap_total_bytes' => 2 * 1024 ** 3,
            'swap_used_bytes' => $name === 'db-01' ? 180 * 1024 ** 2 : 0,
            'disks' => self::disks($name, $at, $anchor),
            'network_rx_bytes' => (int) ($seconds * $rate * (1 + 0.4 * $day)),
            'network_tx_bytes' => (int) ($seconds * $rate * 2.6 * (1 + 0.4 * $day)),
            'disk_read_bytes' => $seconds * 52_000,
            'disk_write_bytes' => $seconds * ($name === 'db-01' ? 310_000 : 64_000),
            'network_errors_in' => 0,
            'network_errors_out' => 0,
            'process_count' => ['web-01' => 182, 'db-01' => 141, 'worker-01' => 96][$name] ?? 74,
            'uptime_seconds' => 3_600 * 24 * 41 + $seconds % 86_400,
            'agent_version' => '0.4.0',
        ];
    }

    /** @return array<int, array{path: string, total_bytes: int, used_bytes: int, available_bytes: int, percent: float}> */
    private static function disks(string $name, CarbonInterface $at, CarbonInterface $anchor): array
    {
        $disk = fn (string $path, int $gb, float $percent) => [
            'path' => $path, 'total_bytes' => $gb * 1024 ** 3, 'used_bytes' => (int) ($gb * 1024 ** 3 * $percent / 100),
            'available_bytes' => (int) ($gb * 1024 ** 3 * (100 - $percent) / 100), 'percent' => round($percent, 1),
        ];
        $days = ($at->getTimestamp() - $anchor->getTimestamp()) / 86_400;

        return match ($name) {
            // The data disk fills up steadily: about 3 points a day, full in roughly five days.
            'db-01' => [$disk('/', 40, 38), $disk('/var/lib/mysql', 200, max(40, min(99.5, 84 + 3.1 * $days)))],
            'web-01' => [$disk('/', 80, 48)],
            'worker-01' => [$disk('/', 40, 35)],
            default => [$disk('/', 40, 22)],
        };
    }
}
