<?php

namespace App\Services\Reports;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Incidents\IncidentMetrics;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Availability report for a period.
 *
 * Domains: uptime = successful / performed HTTP checks; coverage =
 * performed / expected checks; observed downtime = failed checks x
 * interval. Missing checks are never treated as successful.
 *
 * Servers: downtime = time covered by agent-offline incidents in the
 * period (incidents are not opened during maintenance, so planned
 * maintenance does not count as downtime); coverage = received samples /
 * expected samples (one per minute) since the server existed.
 *
 * This is calculated availability from monitoring data, not contractual
 * SLA compliance.
 */
class AvailabilityReport
{
    public function __construct(
        private UptimeCalculator $uptime,
        private IncidentMetrics $metrics,
    ) {}

    public function build(CarbonInterface $from, CarbonInterface $to, float $target): array
    {
        $domains = Domain::query()->withTrashed()
            ->where('created_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $from))
            ->orderBy('name')->get()
            ->map(function (Domain $domain) use ($from, $to, $target) {
                $stats = $this->uptime->domain($domain, $from, $to);
                $incidents = Incident::query()->where('subject_type', 'domain')->where('subject_id', $domain->id)
                    ->whereBetween('opened_at', [$from, $to])->count();

                return [
                    'domain' => $domain,
                    ...$stats,
                    'incidents' => $incidents,
                    'meets_target' => $stats['uptime'] === null ? null : $stats['uptime'] >= $target,
                ];
            });

        $servers = Server::query()->withTrashed()
            ->where('created_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $from))
            ->orderBy('name')->get()
            ->map(fn (Server $server) => $this->server($server, $from, $to, $target));

        $all = $domains->pluck('uptime')->concat($servers->pluck('uptime'))->filter(fn ($v) => $v !== null);

        return [
            'from' => $from,
            'to' => $to,
            'target' => $target,
            'domains' => $domains,
            'servers' => $servers,
            'incidents' => $this->metrics->summary($from, $to),
            'average_uptime' => $all->isEmpty() ? null : round($all->avg(), 3),
        ];
    }

    private function server(Server $server, CarbonInterface $from, CarbonInterface $to, float $target): array
    {
        $start = $server->created_at->gt($from) ? $server->created_at : $from;
        $end = $server->deleted_at && $server->deleted_at->lt($to) ? $server->deleted_at : $to;
        $observed = max(0, $start->diffInSeconds($end, false));

        $downtime = 0;

        Incident::query()->where('subject_type', 'server')->where('subject_id', $server->id)->where('source', 'agent')
            ->where('opened_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start))
            ->get(['opened_at', 'resolved_at'])
            ->each(function (Incident $incident) use (&$downtime, $start, $end): void {
                $s = $incident->opened_at->max($start);
                $e = ($incident->resolved_at ?? $end)->min($end);
                $downtime += max(0, $s->diffInSeconds($e, false));
            });

        $samples = DB::table('metric_samples')->where('server_id', $server->id)->whereBetween('collected_at', [$start, $end])->count();
        $expected = max(1, (int) floor($observed / 60));

        $uptime = $observed > 0 && $samples > 0 ? round(max(0, 1 - $downtime / $observed) * 100, 3) : null;

        return [
            'server' => $server,
            'observed_seconds' => $observed,
            'downtime_minutes' => round($downtime / 60, 1),
            'uptime' => $uptime,
            'coverage' => $observed > 0 ? round(min(100, $samples / $expected * 100), 1) : null,
            'samples' => $samples,
            'incidents' => Incident::query()->where('subject_type', 'server')->where('subject_id', $server->id)->whereBetween('opened_at', [$from, $to])->count(),
            'meets_target' => $uptime === null ? null : $uptime >= $target,
        ];
    }
}
