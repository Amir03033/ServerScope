<?php

namespace App\Services\Websites;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Support\Time;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated health of a website: page states, response-time
 * percentiles, availability per day, active incidents and recent
 * content, DNS and certificate changes.
 */
class WebsiteOverview
{
    /** Percentiles are only shown with at least this many samples. */
    public const MIN_SAMPLES_FOR_PERCENTILES = 20;

    /**
     * @return array<string, int>
     */
    public function counts(Domain $domain): array
    {
        $rows = MonitoredPage::query()
            ->where('domain_id', $domain->id)
            ->selectRaw('approval_status, is_active, status, COUNT(*) as n')
            ->groupBy('approval_status', 'is_active', 'status')
            ->get();

        $counts = ['total' => 0, 'up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0, 'paused' => 0, 'pending' => 0, 'rejected' => 0, 'stale' => 0];

        foreach ($rows as $row) {
            $n = (int) $row->n;

            if ($row->approval_status !== 'approved') {
                $counts[$row->approval_status] = ($counts[$row->approval_status] ?? 0) + $n;

                continue;
            }

            $counts['total'] += $n;

            if (! $row->is_active) {
                $counts['paused'] += $n;
            } else {
                $counts[$row->status] = ($counts[$row->status] ?? 0) + $n;
            }
        }

        $counts['stale'] = $this->stalePages($domain)->count();

        return $counts;
    }

    /** Monitored pages not checked within twice their interval (plus five minutes). */
    public function stalePages(Domain $domain): Collection
    {
        $now = now();

        return MonitoredPage::query()
            ->where('domain_id', $domain->id)
            ->monitored()
            ->get(['id', 'url', 'check_interval_minutes', 'last_checked_at', 'created_at'])
            ->filter(fn (MonitoredPage $page) => ($page->last_checked_at ?? $page->created_at)->lt($now->copy()->subMinutes($page->check_interval_minutes * 2 + 5)))
            ->values();
    }

    /**
     * Response-time statistics of successful checks.
     *
     * @return array{samples: int, avg: ?float, p50: ?float, p95: ?float, p99: ?float, checks: int, failures: int, availability: ?float}
     */
    public function responseStats(Domain $domain, Carbon $from, Carbon $to, ?int $pageId = null): array
    {
        $base = DB::table('page_checks')
            ->where('domain_id', $domain->id)
            ->when($pageId, fn ($q) => $q->where('monitored_page_id', $pageId))
            ->whereBetween('checked_at', [$from, $to]);

        $totals = (clone $base)->selectRaw('COUNT(*) as checks, SUM(CASE WHEN success THEN 0 ELSE 1 END) as failures')->first();

        $times = (clone $base)->where('success', true)->whereNotNull('response_time_ms')
            ->orderBy('response_time_ms')->limit(50_000)->pluck('response_time_ms')->map(fn ($v) => (float) $v)->values();

        $percentile = function (float $p) use ($times): ?float {
            if ($times->count() < self::MIN_SAMPLES_FOR_PERCENTILES) {
                return null;
            }

            $index = (int) ceil($p * $times->count()) - 1;

            return round($times[max(0, min($times->count() - 1, $index))], 1);
        };

        $checks = (int) ($totals->checks ?? 0);
        $failures = (int) ($totals->failures ?? 0);

        return [
            'samples' => $times->count(),
            'avg' => $times->isEmpty() ? null : round($times->avg(), 1),
            'p50' => $percentile(0.50),
            'p95' => $percentile(0.95),
            'p99' => $percentile(0.99),
            'checks' => $checks,
            'failures' => $failures,
            'availability' => $checks > 0 ? round(($checks - $failures) / $checks * 100, 3) : null,
        ];
    }

    /**
     * Availability per day over all pages (display timezone).
     *
     * @return array<int, array{date: string, checks: int, successes: int, uptime: ?float}>
     */
    public function daily(Domain $domain, int $days = 14, ?int $pageId = null): array
    {
        $zone = Time::zone();
        $start = now($zone)->startOfDay()->subDays($days - 1);
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[$start->copy()->addDays($i)->format('Y-m-d')] = ['checks' => 0, 'successes' => 0];
        }

        $rows = DB::table('page_checks')
            ->where('domain_id', $domain->id)
            ->when($pageId, fn ($q) => $q->where('monitored_page_id', $pageId))
            ->where('checked_at', '>=', $start->copy()->utc())
            ->select(['checked_at', 'success'])
            ->orderBy('id')
            ->lazy(5000);

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

    /** Active incidents about the website itself or any of its pages. */
    public function activeIncidents(Domain $domain): Collection
    {
        $pageIds = MonitoredPage::query()->where('domain_id', $domain->id)->pluck('id');

        return Incident::query()
            ->active()
            ->where(function ($q) use ($domain, $pageIds): void {
                $q->where(fn ($q) => $q->where('subject_type', 'domain')->where('subject_id', $domain->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'page')->whereIn('subject_id', $pageIds))
                    ->orWhereHas('impacts', fn ($q) => $q->where('resource_type', 'page')->whereIn('resource_id', $pageIds)->where('status', 'failing'));
            })
            ->withCount(['impacts as failing_pages_count' => fn ($q) => $q->where('resource_type', 'page')->where('status', 'failing')])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->latest('opened_at')
            ->limit(20)
            ->get();
    }

    /**
     * Recent content/header, DNS and certificate changes, newest first.
     *
     * @return Collection<int, array{type: string, at: Carbon, title: string, detail: ?string, url: ?string}>
     */
    public function recentChanges(Domain $domain, int $limit = 15): Collection
    {
        $content = DB::table('page_changes')->join('monitored_pages', 'monitored_pages.id', '=', 'page_changes.monitored_page_id')
            ->where('page_changes.domain_id', $domain->id)
            ->latest('page_changes.detected_at')->limit($limit)
            ->get(['page_changes.kind', 'page_changes.summary', 'page_changes.significance', 'page_changes.detected_at', 'page_changes.acknowledged_at', 'monitored_pages.url', 'monitored_pages.id as page_id'])
            ->map(fn ($c) => [
                'type' => $c->kind === 'headers' ? 'headers' : 'content',
                'at' => Carbon::parse($c->detected_at, 'UTC'),
                'title' => ($c->kind === 'headers' ? 'Headers gewijzigd: ' : ($c->significance === 'significant' ? 'Significante inhoudswijziging: ' : 'Kleine inhoudswijziging: ')).$c->url,
                'detail' => $c->summary.($c->acknowledged_at ? ' · bevestigd' : ''),
                'url' => route('pages.show', $c->page_id),
            ]);

        $dns = $domain->dnsChanges()->latest('detected_at')->limit($limit)->get()->map(fn ($c) => [
            'type' => 'dns',
            'at' => $c->detected_at,
            'title' => "DNS {$c->record_type} gewijzigd",
            'detail' => trim((count($c->added) ? '+ '.implode(', ', array_slice($c->added, 0, 3)).' ' : '').(count($c->removed) ? '− '.implode(', ', array_slice($c->removed, 0, 3)) : '')),
            'url' => route('domains.show', $domain),
        ]);

        $ssl = $domain->checks()->where('type', 'ssl')->where('message', 'like', 'Nieuw certificaat%')->latest('checked_at')->limit($limit)->get()->map(fn ($c) => [
            'type' => 'ssl',
            'at' => $c->checked_at,
            'title' => 'Nieuw certificaat gedetecteerd',
            'detail' => $c->details['issuer'] ?? null,
            'url' => route('domains.show', $domain),
        ]);

        return $content->concat($dns)->concat($ssl)->sortByDesc(fn ($c) => $c['at']->getTimestamp())->take($limit)->values();
    }
}
