<?php

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Support\Time;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;

/**
 * Incident metrics from stored timestamps.
 *
 * - MTTA: mean of (acknowledged_at - opened_at) over incidents opened in
 *   the period that were acknowledged (the first human response:
 *   acknowledge or start investigating).
 * - MTTR: mean of (resolved_at - opened_at) over incidents opened in the
 *   period that are resolved or closed. For a reopened incident the last
 *   resolution counts, so time spent reopened is included.
 * Incidents without the relevant timestamp are excluded, not counted as 0.
 */
class IncidentMetrics
{
    /**
     * @return array{opened: int, acknowledged: int, resolved: int, mtta_seconds: ?int, mttr_seconds: ?int, by_severity: array<string, int>, by_source: array<string, int>}
     */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?Builder $scope = null): array
    {
        $incidents = ($scope ?? Incident::query())
            ->whereBetween('opened_at', [$from, $to])
            ->get(['id', 'severity', 'source', 'opened_at', 'acknowledged_at', 'resolved_at', 'status']);

        $ack = $incidents->filter(fn ($i) => $i->acknowledged_at !== null);
        $resolved = $incidents->filter(fn ($i) => $i->resolved_at !== null && in_array($i->status, ['resolved', 'closed'], true));

        return [
            'opened' => $incidents->count(),
            'acknowledged' => $ack->count(),
            'resolved' => $resolved->count(),
            'mtta_seconds' => $ack->isEmpty() ? null : (int) round($ack->avg(fn ($i) => $i->opened_at->diffInSeconds($i->acknowledged_at, true))),
            'mttr_seconds' => $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($i) => $i->opened_at->diffInSeconds($i->resolved_at, true))),
            'by_severity' => $incidents->countBy('severity')->all(),
            'by_source' => $incidents->countBy('source')->all(),
        ];
    }

    /**
     * Incidents opened per day (display timezone).
     *
     * @return array<string, int>
     */
    public function perDay(int $days, ?Builder $scope = null): array
    {
        $zone = Time::zone();
        $start = now($zone)->startOfDay()->subDays($days - 1);
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $series[$start->copy()->addDays($i)->format('Y-m-d')] = 0;
        }

        ($scope ?? Incident::query())
            ->where('opened_at', '>=', $start->copy()->utc())
            ->pluck('opened_at')
            ->each(function ($openedAt) use (&$series, $zone): void {
                $key = $openedAt->copy()->setTimezone($zone)->format('Y-m-d');

                if (isset($series[$key])) {
                    $series[$key]++;
                }
            });

        return $series;
    }

    public static function humanDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        if ($seconds < 60) {
            return $seconds.' s';
        }

        return CarbonInterval::seconds($seconds)->cascade()->locale('nl')->forHumans(['short' => true, 'parts' => 2]);
    }
}
