<?php

namespace App\Services\Reports;

use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Support\Time;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for the dashboard overview. Counts come straight from indexed
 * columns; the heavier check aggregates are limited to short windows and
 * cached for a minute.
 */
class DashboardOverview
{
    public function data(): array
    {
        $now = now();

        $servers = [
            'total' => Server::query()->count(),
            'online' => Server::query()->withDisplayStatus('online', $now)->count(),
            'offline' => Server::query()->withDisplayStatus('offline', $now)->count(),
            'maintenance' => Server::query()->withDisplayStatus('maintenance', $now)->count(),
            'pending' => Server::query()->withDisplayStatus('pending', $now)->count(),
        ];

        $domainStatus = Domain::query()->where('is_active', true)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $domains = [
            'total' => Domain::query()->count(),
            'paused' => Domain::query()->where('is_active', false)->count(),
            'healthy' => (int) ($domainStatus['healthy'] ?? 0),
            'warning' => (int) ($domainStatus['warning'] ?? 0),
            'critical' => (int) ($domainStatus['critical'] ?? 0),
            'unknown' => (int) ($domainStatus['unknown'] ?? 0),
            'dns_problems' => Domain::query()->where('is_active', true)->where('dns_status', 'failed')->count(),
            'ssl_problems' => Domain::query()->where('is_active', true)->whereIn('ssl_status', [...Domain::SSL_INVALID_STATES, 'expiring'])->count(),
            'http_problems' => Domain::query()->where('is_active', true)->where('http_status', 'down')->count(),
        ];

        $incidents = [
            'active' => Incident::query()->active()->count(),
            'critical' => Incident::query()->active()->where('severity', 'critical')->count(),
            'unacknowledged' => Incident::query()->where('status', 'open')->whereNull('acknowledged_at')->count(),
            'list' => Incident::query()->active()->with('subject')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->latest('opened_at')->limit(6)->get(),
            'recent_resolved' => Incident::query()->where('status', 'resolved')->latest('resolved_at')->limit(3)->get(),
        ];

        return [
            'servers' => $servers,
            'domains' => $domains,
            'incidents' => $incidents,
            'expirations' => $this->expirations(60),
            'dnsChanges' => DnsChange::query()->with('domain:id,name')->latest('detected_at')->limit(8)->get(),
            // Cached per team: the trends contain the team's own checks only.
            'trends' => Cache::remember('dashboard:trends:'.(\App\Tenancy\Tenant::id() ?? 'all'), 60, fn () => $this->trends()),
        ];
    }

    /**
     * Upcoming certificate and registration expiries (verified data only).
     */
    public function expirations(int $days): Collection
    {
        $until = now()->addDays($days);

        $ssl = Domain::query()->where('is_active', true)->where('ssl_enabled', true)
            ->whereNotNull('ssl_valid_to')->where('ssl_valid_to', '<=', $until)
            ->get(['id', 'name', 'ssl_valid_to'])
            ->map(fn (Domain $d) => ['type' => 'ssl', 'domain' => $d, 'at' => $d->ssl_valid_to, 'days' => $d->sslDaysRemaining()]);

        $registration = Domain::query()->where('is_active', true)->where('expiry_enabled', true)
            ->where('expiry_status', 'verified')->whereNotNull('registration_expires_at')->where('registration_expires_at', '<=', $until)
            ->get(['id', 'name', 'registration_expires_at', 'registrable_domain', 'expiry_status'])
            ->map(fn (Domain $d) => ['type' => 'registration', 'domain' => $d, 'at' => $d->registration_expires_at, 'days' => $d->expiryDaysRemaining()]);

        return $ssl->concat($registration)->sortBy(fn ($e) => $e['at']->getTimestamp())->values()->take(10);
    }

    /**
     * Hourly average response time (24 h) and daily uptime (14 days)
     * across the (current team's) domains. Days without checks have no value.
     */
    private function trends(): array
    {
        // Domain query carries the team scope; raw check queries do not.
        $domains = Domain::query()->withTrashed()->select('id');

        $from = now()->subDay()->startOfHour();
        $hours = [];

        for ($i = 0; $i <= 24; $i++) {
            $hours[$from->copy()->addHours($i)->format('Y-m-d H')] = ['sum' => 0.0, 'n' => 0, 'fail' => 0];
        }

        DB::table('domain_checks')->whereIn('domain_id', $domains)->where('type', 'http')->where('checked_at', '>=', $from)
            ->select(['checked_at', 'success', 'response_time_ms'])->orderBy('id')->lazy(5000)
            ->each(function ($row) use (&$hours): void {
                $key = Carbon::parse($row->checked_at, 'UTC')->format('Y-m-d H');

                if (! isset($hours[$key])) {
                    return;
                }

                if ($row->success && $row->response_time_ms !== null) {
                    $hours[$key]['sum'] += (float) $row->response_time_ms;
                    $hours[$key]['n']++;
                } elseif (! $row->success) {
                    $hours[$key]['fail']++;
                }
            });

        $response = collect($hours)->map(fn ($h, $key) => [
            'label' => Time::format(Carbon::createFromFormat('Y-m-d H', $key, 'UTC'), 'H:i'),
            'value' => $h['n'] > 0 ? round($h['sum'] / $h['n'], 1) : null,
            'flag' => $h['fail'] > 0,
        ])->values()->all();

        $uptime = DB::table('domain_checks')->whereIn('domain_id', $domains)->where('type', 'http')->where('checked_at', '>=', now()->subDays(14)->startOfDay())
            ->selectRaw('DATE(checked_at) as day, COUNT(*) as checks, SUM(CASE WHEN success THEN 1 ELSE 0 END) as ok')
            ->groupBy('day')->orderBy('day')->get()
            ->keyBy('day');

        $daily = [];

        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $row = $uptime[$day] ?? null;
            $daily[] = ['date' => $day, 'checks' => (int) ($row->checks ?? 0), 'uptime' => $row && $row->checks > 0 ? round($row->ok / $row->checks * 100, 2) : null];
        }

        $totals = DB::table('domain_checks')->whereIn('domain_id', $domains)->where('type', 'http')->where('checked_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as checks, SUM(CASE WHEN success THEN 1 ELSE 0 END) as ok')->first();

        return [
            'response' => $response,
            'daily' => $daily,
            'uptime_24h' => $totals->checks > 0 ? round($totals->ok / $totals->checks * 100, 2) : null,
            'checks_24h' => (int) $totals->checks,
        ];
    }
}
