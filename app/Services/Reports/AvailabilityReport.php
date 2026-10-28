<?php

namespace App\Services\Reports;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\ServiceCheck;
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
 * Pages: like domains, from the page's own checks (page_checks); a
 * degraded (slow) check counts as available.
 *
 * Targets: a resource's own availability target (sla_target) is used
 * when set, otherwise the report target. Maintenance periods are NOT
 * excluded from domain and page uptime (checks keep running and count as
 * performed); for servers no downtime is recorded during maintenance
 * because no incident is opened. See docs/REPORTS.md.
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

                $resourceTarget = $domain->sla_target ?? $target;

                return [
                    'domain' => $domain,
                    ...$stats,
                    'incidents' => $incidents,
                    'target' => $resourceTarget,
                    'meets_target' => $stats['uptime'] === null ? null : $stats['uptime'] >= $resourceTarget,
                ];
            });

        $servers = Server::query()->withTrashed()
            ->where('created_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $from))
            ->orderBy('name')->get()
            ->map(fn (Server $server) => $this->server($server, $from, $to, $target));

        $pages = $this->pages($from, $to, $target);
        $all = $domains->pluck('uptime')->concat($servers->pluck('uptime'))->filter(fn ($v) => $v !== null);

        return [
            'pages' => $pages,
            'coverage' => $this->coverage($from, $to, $domains, $servers, $pages),
            'recurring' => $this->recurring($from, $to),
            'incident_duration_seconds' => $this->incidentDuration($from, $to),
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
            'target' => $server->sla_target ?? $target,
            'meets_target' => $uptime === null ? null : $uptime >= ($server->sla_target ?? $target),
        ];
    }

    /** Availability of monitored website pages from their own checks. */
    private function pages(CarbonInterface $from, CarbonInterface $to, float $target)
    {
        // Raw query: limited to the pages of the current team explicitly.
        $stats = DB::table('page_checks')
            ->whereIn('monitored_page_id', MonitoredPage::query()->select('id'))
            ->whereBetween('checked_at', [$from, $to])
            ->groupBy('monitored_page_id')
            ->selectRaw('monitored_page_id, COUNT(*) as checks, SUM(CASE WHEN success THEN 1 ELSE 0 END) as successes, AVG(CASE WHEN success THEN response_time_ms END) as avg_ms')
            ->get()
            ->keyBy('monitored_page_id');

        $minutes = max(1, $from->diffInMinutes($to, true));

        return MonitoredPage::query()->with('domain')
            ->where('approval_status', 'approved')
            ->where('created_at', '<=', $to)
            ->orderBy('domain_id')->orderBy('url')
            ->get()
            ->map(function (MonitoredPage $page) use ($stats, $target, $from, $to, $minutes) {
                $row = $stats->get($page->id);
                $checks = (int) ($row->checks ?? 0);
                $successes = (int) ($row->successes ?? 0);
                $uptime = $checks > 0 ? round($successes / $checks * 100, 3) : null;
                $pageTarget = $page->sla_target ?? $page->domain?->sla_target ?? $target;
                $observed = max(1, min($minutes, $page->created_at->max($from)->diffInMinutes($to, true)));
                $expected = max(1, (int) floor($observed / max(1, $page->check_interval_minutes)));

                return [
                    'page' => $page,
                    'checks' => $checks,
                    'uptime' => $uptime,
                    'coverage' => $page->is_active ? round(min(100, $checks / $expected * 100), 1) : null,
                    'avg_ms' => isset($row->avg_ms) ? round((float) $row->avg_ms, 1) : null,
                    'p95_ms' => $this->percentile($page->id, $from, $to, 0.95),
                    'downtime_minutes' => $checks > 0 ? ($checks - $successes) * $page->check_interval_minutes : null,
                    'incidents' => Incident::query()->where('subject_type', 'page')->where('subject_id', $page->id)->whereBetween('opened_at', [$from, $to])->count(),
                    'target' => $pageTarget,
                    'meets_target' => $uptime === null ? null : $uptime >= $pageTarget,
                ];
            });
    }

    private function percentile(int $pageId, CarbonInterface $from, CarbonInterface $to, float $p): ?float
    {
        $times = DB::table('page_checks')->where('monitored_page_id', $pageId)->where('success', true)
            ->whereBetween('checked_at', [$from, $to])->whereNotNull('response_time_ms')
            ->orderBy('response_time_ms')->limit(20_000)->pluck('response_time_ms');

        if ($times->count() < 20) {
            return null;
        }

        return round((float) $times[(int) ceil($p * $times->count()) - 1], 1);
    }

    /**
     * How much of the estate was actually monitored in the period.
     *
     * @return array<string, mixed>
     */
    private function coverage(CarbonInterface $from, CarbonInterface $to, $domains, $servers, $pages): array
    {
        $low = fn ($rows) => $rows->filter(fn ($r) => $r['coverage'] !== null && $r['coverage'] < 90)->count();

        return [
            'servers' => $servers->count(),
            'servers_reporting' => $servers->filter(fn ($r) => $r['samples'] > 0)->count(),
            'servers_low_coverage' => $low($servers),
            'domains' => $domains->count(),
            'domains_checked' => $domains->filter(fn ($r) => $r['checks'] > 0)->count(),
            'domains_low_coverage' => $low($domains),
            'pages' => $pages->count(),
            'pages_checked' => $pages->filter(fn ($r) => $r['checks'] > 0)->count(),
            'pages_low_coverage' => $low($pages),
            'pages_pending' => MonitoredPage::query()->where('approval_status', 'pending')->count(),
            'pages_paused' => MonitoredPage::query()->approved()->where('is_active', false)->count(),
            'services' => ServiceCheck::query()->where('is_enabled', true)->count(),
            'servers_with_services' => ServiceCheck::query()->where('is_enabled', true)->distinct()->count('server_id'),
        ];
    }

    /** Problems that occurred more than once in the period. */
    private function recurring(CarbonInterface $from, CarbonInterface $to)
    {
        return Incident::query()
            ->whereBetween('opened_at', [$from, $to])
            ->where('source', '!=', 'manual')
            ->get(['id', 'fingerprint', 'title', 'reopened_count', 'opened_at', 'resolved_at', 'subject_type', 'subject_id'])
            ->groupBy('fingerprint')
            ->map(fn ($group) => [
                'fingerprint' => $group->first()->fingerprint,
                'title' => $group->sortByDesc('opened_at')->first()->title,
                'latest_id' => $group->sortByDesc('opened_at')->first()->id,
                'occurrences' => $group->count() + $group->sum('reopened_count'),
                'total_seconds' => (int) $group->sum(fn ($i) => $i->opened_at->diffInSeconds($i->resolved_at ?? now(), true)),
            ])
            ->filter(fn ($row) => $row['occurrences'] > 1)
            ->sortByDesc('occurrences')
            ->take(10)
            ->values();
    }

    /** Total incident time within the period (overlap counted per incident). */
    private function incidentDuration(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Incident::query()
            ->where('opened_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $from))
            ->whereNull('parent_id')
            ->get(['opened_at', 'resolved_at'])
            ->sum(fn ($i) => max(0, $i->opened_at->max($from)->diffInSeconds(($i->resolved_at ?? $to)->min($to), false)));
    }
}
