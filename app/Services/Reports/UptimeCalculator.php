<?php

namespace App\Services\Reports;

use App\Models\Domain;
use App\Support\Time;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Availability from stored HTTP check results.
 *
 * Uptime = successful checks / performed checks. Periods without checks
 * (paused monitoring, scheduler outages) are NOT counted as up: they
 * lower "coverage" (performed / expected checks) instead, so the two
 * numbers together show how trustworthy the uptime figure is.
 */
class UptimeCalculator
{
    /**
     * @return array{checks: int, successes: int, uptime: ?float, coverage: ?float, avg_ms: ?float, max_ms: ?float, downtime_minutes: ?float}
     */
    public function domain(Domain $domain, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = DB::table('domain_checks')
            ->where('domain_id', $domain->id)
            ->where('type', 'http')
            ->whereBetween('checked_at', [$from, $to])
            ->selectRaw('COUNT(*) as checks, SUM(CASE WHEN success THEN 1 ELSE 0 END) as successes, AVG(CASE WHEN success THEN response_time_ms END) as avg_ms, MAX(CASE WHEN success THEN response_time_ms END) as max_ms')
            ->first();

        $checks = (int) $row->checks;
        $successes = (int) $row->successes;
        $minutes = max(1, $from->diffInMinutes($to, true));
        $expected = max(1, (int) floor($minutes / max(1, $domain->check_interval_minutes)));

        return [
            'checks' => $checks,
            'successes' => $successes,
            'uptime' => $checks > 0 ? round($successes / $checks * 100, 3) : null,
            'coverage' => round(min(100, $checks / $expected * 100), 1),
            'avg_ms' => $row->avg_ms !== null ? round((float) $row->avg_ms, 1) : null,
            'max_ms' => $row->max_ms !== null ? round((float) $row->max_ms, 1) : null,
            // Each failed check stands for one interval of observed downtime.
            'downtime_minutes' => $checks > 0 ? ($checks - $successes) * $domain->check_interval_minutes : null,
        ];
    }

    /**
     * Daily buckets (display timezone) for an uptime strip.
     *
     * @return array<int, array{date: string, checks: int, successes: int, uptime: ?float}>
     */
    public function dailyBuckets(Domain $domain, int $days): array
    {
        $zone = Time::zone();
        $start = now($zone)->startOfDay()->subDays($days - 1);
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[$start->copy()->addDays($i)->format('Y-m-d')] = ['checks' => 0, 'successes' => 0];
        }

        $rows = DB::table('domain_checks')
            ->where('domain_id', $domain->id)
            ->where('type', 'http')
            ->where('checked_at', '>=', $start->copy()->utc())
            ->orderBy('id')
            ->select(['checked_at', 'success'])
            ->lazy(2000);

        foreach ($rows as $row) {
            $date = Carbon::parse($row->checked_at, 'UTC')->setTimezone($zone)->format('Y-m-d');

            if (isset($buckets[$date])) {
                $buckets[$date]['checks']++;
                $buckets[$date]['successes'] += (int) $row->success;
            }
        }

        return collect($buckets)->map(fn ($b, $date) => [
            'date' => $date,
            'checks' => $b['checks'],
            'successes' => $b['successes'],
            'uptime' => $b['checks'] > 0 ? round($b['successes'] / $b['checks'] * 100, 2) : null,
        ])->values()->all();
    }

    /**
     * Average response time per bucket for a chart.
     *
     * @return array<int, array{at: string, avg_ms: ?float, failures: int}>
     */
    public function responseSeries(Domain $domain, CarbonInterface $from, CarbonInterface $to, int $buckets = 48): array
    {
        $span = max(60, $from->diffInSeconds($to, true));
        $size = $span / $buckets;
        $series = array_fill(0, $buckets, ['sum' => 0.0, 'n' => 0, 'failures' => 0]);

        $rows = DB::table('domain_checks')
            ->where('domain_id', $domain->id)
            ->where('type', 'http')
            ->whereBetween('checked_at', [$from, $to])
            ->select(['checked_at', 'success', 'response_time_ms'])
            ->orderBy('id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $offset = Carbon::parse($row->checked_at, 'UTC')->getTimestamp() - $from->getTimestamp();
            $index = min($buckets - 1, max(0, (int) floor($offset / $size)));

            if ($row->success && $row->response_time_ms !== null) {
                $series[$index]['sum'] += (float) $row->response_time_ms;
                $series[$index]['n']++;
            } elseif (! $row->success) {
                $series[$index]['failures']++;
            }
        }

        return array_map(fn ($b, $i) => [
            'at' => $from->copy()->addSeconds((int) ($i * $size))->toIso8601String(),
            'avg_ms' => $b['n'] > 0 ? round($b['sum'] / $b['n'], 1) : null,
            'failures' => $b['failures'],
        ], $series, array_keys($series));
    }
}
