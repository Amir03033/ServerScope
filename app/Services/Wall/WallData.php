<?php

namespace App\Services\Wall;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\LogEntry;
use App\Models\Server;
use App\Tenancy\Tenant;
use Illuminate\Support\Carbon;

/**
 * Everything the wall screen shows besides the topology: per server its
 * latest measurements, every website with response time and certificate,
 * the scheduled jobs, open and recent incidents, deploys and log errors.
 * One team, bounded lists, cheap enough to poll every 30 seconds.
 */
class WallData
{
    public const LIMIT = 24;

    /** @return array<string, mixed> */
    public function forTeam(int $teamId): array
    {
        return Tenant::run($teamId, fn () => [
            'generated_at' => now()->toIso8601String(),
            'servers' => $this->servers(),
            'sites' => $this->sites(),
            'jobs' => $this->jobs(),
            'incidents' => $this->incidents(),
            'deploys' => $this->deploys(),
            'logs' => $this->logs(),
        ]);
    }

    private function servers(): array
    {
        $now = now();

        return Server::query()->with('latestMetricSample')->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'team_id', 'name', 'operating_system', 'agent_version', 'status', 'last_seen_at', 'maintenance_starts_at', 'maintenance_ends_at'])
            ->map(function (Server $server) use ($now) {
                $s = $server->latestMetricSample;
                $disk = collect($s?->disks ?? [])->max(fn ($d) => (float) ($d['percent'] ?? 0));

                return [
                    'key' => "server:{$server->id}",
                    'name' => $server->name,
                    'status' => $server->displayStatus($now),
                    'os' => $server->operating_system,
                    'agent' => $server->agent_version,
                    'cpu' => $s?->cpu_percent,
                    'memory' => $s && $s->memory_total_bytes ? round($s->memory_used_bytes / $s->memory_total_bytes * 100, 1) : null,
                    'disk' => $s ? round((float) $disk, 1) : null,
                    'load' => $s?->load_1,
                    'uptime' => $s?->uptime_seconds,
                    'last_seen_at' => $server->last_seen_at?->toIso8601String(),
                ];
            })->all();
    }

    private function sites(): array
    {
        return Domain::query()->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Domain $domain) => [
                'name' => $domain->displayName(),
                'status' => $domain->displayStatus(),
                'response_ms' => $domain->http_last_response_ms,
                'checked_at' => $domain->http_last_checked_at?->toIso8601String(),
                'ssl_days' => $domain->sslDaysRemaining(),
            ])->all();
    }

    private function jobs(): array
    {
        return Heartbeat::query()->with('server:id,name')
            ->orderByRaw("case status when 'down' then 0 when 'up' then 1 when 'new' then 2 else 3 end")->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'name', 'status', 'server_id', 'last_ping_at', 'last_duration_ms'])
            ->map(fn (Heartbeat $job) => [
                'name' => $job->name,
                'status' => $job->status,
                'server' => $job->server?->name,
                'last_ping_at' => $job->last_ping_at?->toIso8601String(),
                'duration_ms' => $job->last_duration_ms,
            ])->all();
    }

    private function incidents(): array
    {
        $day = now()->subDay();
        $resolved = Incident::query()->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(7))->get(['opened_at', 'resolved_at']);
        $mttr = $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($i) => max(0, $i->opened_at->diffInMinutes($i->resolved_at))));
        $row = fn (Incident $i) => [
            'title' => $i->title,
            'severity' => $i->severity,
            'status' => $i->status,
            'subject' => $i->subjectName(),
            'opened_at' => $i->opened_at?->toIso8601String(),
            'resolved_at' => $i->resolved_at?->toIso8601String(),
        ];

        return [
            'open' => Incident::query()->active()->with('subject')->latest('opened_at')->limit(8)->get()->map($row)->all(),
            'recent' => Incident::query()->whereNotNull('resolved_at')->with('subject')->latest('resolved_at')->limit(5)->get()->map($row)->all(),
            'opened_24h' => Incident::query()->where('opened_at', '>=', $day)->count(),
            'resolved_24h' => Incident::query()->where('resolved_at', '>=', $day)->count(),
            'mttr_minutes' => $mttr,
        ];
    }

    private function deploys(): array
    {
        return Deployment::query()->latest('deployed_at')->limit(6)
            ->get(['id', 'application', 'environment', 'version', 'title', 'deployed_at', 'actor'])
            ->map(fn (Deployment $d) => [
                'title' => $d->title ?: trim(($d->application ?? '').' '.($d->version ?? '')),
                'environment' => $d->environment,
                'actor' => $d->actor,
                'deployed_at' => $d->deployed_at?->toIso8601String(),
            ])->all();
    }

    private function logs(): array
    {
        $hour = now()->subHour();
        $base = LogEntry::query()->where('occurred_at', '>=', $hour);

        return [
            'errors_1h' => (clone $base)->whereIn('level', LogEntry::ERROR_LEVELS)->count(),
            'warnings_1h' => (clone $base)->where('level', 'warning')->count(),
            'top' => (clone $base)->whereIn('level', LogEntry::ERROR_LEVELS)
                ->selectRaw('fingerprint, max(message) as message, count(*) as total, max(occurred_at) as last_at')
                ->groupBy('fingerprint')->orderByDesc('total')->limit(3)->get()
                ->map(fn ($r) => ['message' => mb_strimwidth((string) $r->message, 0, 140, '…'), 'count' => (int) $r->total, 'last_at' => Carbon::parse($r->last_at)->toIso8601String()])->all(),
        ];
    }
}
