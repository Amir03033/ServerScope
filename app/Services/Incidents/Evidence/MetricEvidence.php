<?php

namespace App\Services\Incidents\Evidence;

use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Analytics\MetricHistory;
use App\Services\Analytics\Statistics;
use Carbon\CarbonImmutable;

/**
 * Metric history of a server around an incident: hourly buckets from
 * MetricHistory (rollups for aggregated hours, raw samples for recent
 * ones), raw samples around the onset while retention keeps them, and
 * clear shifts against the server's own baseline (median and MAD).
 */
class MetricEvidence
{
    /** Hours of history before the onset used as the metric baseline. */
    public const BASELINE_HOURS = 24;

    public const MIN_BASELINE_HOURS = 6;

    /** metric => [minimum absolute change, MAD floor, unit] */
    public const SHIFT_RULES = [
        'cpu_percent' => [15.0, 2.0, '%'],
        'memory_percent' => [10.0, 1.0, '%'],
        'swap_percent' => [10.0, 1.0, '%'],
        'load_1' => [1.0, 0.1, ''],
        'disk_percent' => [5.0, 0.5, '%'],
    ];

    public function __construct(private MetricHistory $history) {}

    /** @return array<string, array<int, array<string, float>>> metric key => hour timestamp => bucket */
    public function hourly(Server $server, CarbonImmutable $onset, CarbonImmutable $to): array
    {
        return $this->history->buckets($server, $onset->subHours(self::BASELINE_HOURS + 1), $to, 3600);
    }

    /**
     * Metrics whose value in the hour before the onset differs clearly
     * from the server's own previous 24 hours (robust: median and MAD,
     * plus a minimum absolute change, as in anomaly detection).
     *
     * @return array<int, array{metric: string, before: float, baseline: float, change: float, unit: string, hours: int}>
     */
    public function shifts(Server $server, array $hourly, CarbonImmutable $onset): array
    {
        $onsetHour = intdiv($onset->getTimestamp(), 3600) * 3600;
        $recent = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$onset->subHour(), $onset])
            ->get(['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes', 'swap_total_bytes', 'swap_used_bytes', 'disks']);
        $shifts = [];

        foreach ($hourly as $key => $hours) {
            [$metric, $dimension] = array_pad(explode(':', $key, 2), 2, null);

            if (! isset(self::SHIFT_RULES[$metric])) {
                continue;
            }

            [$minimum, $floor, $unit] = self::SHIFT_RULES[$metric];
            $baseline = array_values(array_map(fn ($b) => $b['mean'], array_filter($hours, fn ($h) => $h < $onsetHour - 3600, ARRAY_FILTER_USE_KEY)));

            if (count($baseline) < self::MIN_BASELINE_HOURS) {
                continue;
            }

            // The hour before the onset: raw samples when kept, else the
            // hourly mean of the hour the onset falls in or the one before.
            $values = $recent->map(fn (MetricSample $s) => self::value($s, $metric, $dimension))->filter(fn ($v) => $v !== null);
            $before = $values->count() >= 5 ? (float) $values->avg() : ($hours[$onsetHour - 3600]['mean'] ?? $hours[$onsetHour]['mean'] ?? null);

            if ($before === null) {
                continue;
            }

            $median = Statistics::median($baseline);
            $mad = Statistics::mad($baseline);
            $change = $before - $median;

            if (abs($change) >= $minimum && abs(Statistics::robustZ($before, $median, $mad, $floor)) >= 3.5) {
                $shifts[] = ['metric' => $key, 'before' => round($before, 2), 'baseline' => round($median, 2), 'change' => round($change, 2), 'unit' => $unit, 'hours' => count($baseline)];
            }
        }

        return $shifts;
    }

    /**
     * Raw-sample facts for NEXUS Intelligence over [from, to]: how many
     * samples arrived (before and during the incident), the longest gap,
     * and uptime resets (reboots). Bounded; only the needed columns.
     *
     * @param  array{onset: CarbonImmutable, end: ?CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}  $window
     * @return array{samples_before: int, samples_during: int, first_at: ?string, last_at: ?string, max_gap_seconds: ?int, reboots: array<int, string>, future_samples: int, truncated: bool}
     */
    public function coverage(Server $server, array $window, CarbonImmutable $now, int $limit = 3000): array
    {
        $end = $window['end'] ?? $window['to'];
        $rows = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$window['from'], $window['to']])->orderBy('collected_at')
            ->limit($limit + 1)->get(['collected_at', 'uptime_seconds']);
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $before = $during = 0;
        $maxGap = null;
        $reboots = [];
        $previous = null;

        foreach ($rows as $row) {
            $at = CarbonImmutable::instance($row->collected_at);
            $at->lt($window['onset']) ? $before++ : ($at->lte($end) ? $during++ : null);

            if ($previous !== null) {
                $maxGap = max($maxGap ?? 0, $at->getTimestamp() - $previous['at']->getTimestamp());

                if ($row->uptime_seconds !== null && $previous['uptime'] !== null && (int) $row->uptime_seconds < (int) $previous['uptime']) {
                    $reboots[] = $at->toIso8601String();
                }
            }

            $previous = ['at' => $at, 'uptime' => $row->uptime_seconds];
        }

        return [
            'samples_before' => $before,
            'samples_during' => $during,
            'first_at' => $rows->first()?->collected_at?->toIso8601String(),
            'last_at' => $rows->last()?->collected_at?->toIso8601String(),
            'max_gap_seconds' => $maxGap,
            'reboots' => array_slice($reboots, 0, 10),
            // Samples dated after the investigation's "now": a wrong agent clock.
            'future_samples' => MetricSample::query()->where('server_id', $server->id)->where('collected_at', '>', $now->addMinutes(5))->limit(100)->count(),
            'truncated' => $truncated,
        ];
    }

    public static function value(MetricSample $sample, string $metric, ?string $dimension): ?float
    {
        return match ($metric) {
            'cpu_percent' => $sample->cpu_percent,
            'load_1' => $sample->load_1,
            'memory_percent' => $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100 : null,
            'swap_percent' => $sample->swap_total_bytes > 0 && $sample->swap_used_bytes !== null ? $sample->swap_used_bytes / $sample->swap_total_bytes * 100 : null,
            'disk_percent' => collect((array) $sample->disks)->firstWhere('path', $dimension)['percent'] ?? null,
            default => null,
        };
    }
}
