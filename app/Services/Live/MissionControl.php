<?php

namespace App\Services\Live;

use App\Models\Deployment;
use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Services\Nexus\TopologyBuilder;
use App\Support\Severity;
use Illuminate\Support\Str;

/**
 * Mission control: "is everything OK?" in one answer, for the top of the
 * dashboard. Read-only and built from data that already exists (the
 * cached NEXUS topology, incidents, deployments, DNS changes, expiry
 * dates); it changes nothing.
 *
 * - score: 100 minus a weight per open problem, 0–100 (null when nothing
 *   is monitored yet). The weights are in WEIGHTS and every problem that
 *   costs points is listed in "issues", so the number is explainable;
 * - tiles: every server and website with its health;
 * - timeline: incidents, deployments and DNS changes of the last 24 hours.
 *
 * Every query filters on team_id explicitly (also safe without a tenant).
 */
class MissionControl
{
    public const WEIGHTS = [
        'incident_major' => 20,   // critical or high
        'incident_minor' => 8,
        'server_offline' => 15,
        'site_down' => 12,
        'warning' => 4,
        'slow' => 3,
        'expiring' => 5,
    ];

    public const SLOW_MS = 1500;

    public const EXPIRING_DAYS = 7;

    public const MAX_TILES = 120;

    public function __construct(private TopologyBuilder $topology) {}

    public function forTeam(int $teamId): array
    {
        $now = now();
        $nodes = collect($this->topology->forTeam($teamId)['nodes']);
        $servers = $nodes->where('type', 'server')->values();
        $sites = $nodes->where('type', 'domain')->values();

        $incidents = Incident::query()->withoutGlobalScopes()->where('team_id', $teamId)->active()
            ->orderByDesc('opened_at')->limit(200)->get(['id', 'title', 'severity', 'opened_at']);
        $major = $incidents->filter(fn (Incident $i) => Severity::rank($i->severity) >= Severity::rank(Severity::HIGH))->count();
        $minor = $incidents->count() - $major;

        $offline = $servers->where('health', 'critical')->count();
        $down = $sites->where('health', 'critical')->count();
        $warnings = $nodes->where('health', 'warning')->count();
        $slow = $sites->filter(fn (array $s) => $s['health'] === 'ok' && ($s['details']['response_ms'] ?? 0) >= self::SLOW_MS)->count();
        $expiring = Domain::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('ssl_valid_to', '<=', $now->copy()->addDays(self::EXPIRING_DAYS))
                ->orWhere('registration_expires_at', '<=', $now->copy()->addDays(self::EXPIRING_DAYS)))
            ->count();

        // Every problem that costs points, worst first; each one says what to open.
        $issues = array_values(array_filter([
            $major ? ['key' => 'incident_major', 'count' => $major, 'tone' => 'bad', 'url' => route('incidents.index')] : null,
            $offline ? ['key' => 'server_offline', 'count' => $offline, 'tone' => 'bad', 'url' => route('servers.index', ['status' => 'offline'])] : null,
            $down ? ['key' => 'site_down', 'count' => $down, 'tone' => 'bad', 'url' => route('domains.index')] : null,
            $minor ? ['key' => 'incident_minor', 'count' => $minor, 'tone' => 'warn', 'url' => route('incidents.index')] : null,
            $expiring ? ['key' => 'expiring', 'count' => $expiring, 'tone' => 'warn', 'url' => route('dashboard').'#expiring'] : null,
            $warnings ? ['key' => 'warning', 'count' => $warnings, 'tone' => 'warn', 'url' => route('nexus.index')] : null,
            $slow ? ['key' => 'slow', 'count' => $slow, 'tone' => 'info', 'url' => route('domains.index')] : null,
        ]));

        foreach ($issues as &$issue) {
            $issue['label'] = trans_choice('mission.issues.'.$issue['key'], $issue['count'], ['count' => $issue['count']]);
        }
        unset($issue);

        $monitored = $servers->isNotEmpty() || $sites->isNotEmpty();
        $score = $monitored
            ? max(0, min(100, 100 - array_sum(array_map(fn (array $i) => self::WEIGHTS[$i['key']] * $i['count'], $issues))))
            : null;

        $grade = $score === null ? 'none' : ($score >= 90 ? 'good' : ($score >= 70 ? 'fair' : 'poor'));
        $first = $issues[0]['label'] ?? null;

        return [
            'score' => $score,
            'grade' => $grade,
            // One sentence: "All good", "All good, 1 website slower than usual" or "Needs attention: …".
            'headline' => match (true) {
                $score === null => __('mission.headline.none'),
                $first === null => __('mission.headline.ok'),
                $grade === 'good' => __('mission.headline.ok_but', ['issue' => Str::lcfirst($first)]),
                default => __('mission.headline.attention', ['issue' => Str::lcfirst($first)]),
            },
            'issues' => $issues,
            'tiles' => $servers->concat($sites)->take(self::MAX_TILES)->map(fn (array $n) => [
                'key' => $n['key'],
                'type' => $n['type'],
                'name' => $n['label'],
                'health' => $n['health'],
                'url' => $n['url'],
                'value' => $n['type'] === 'server'
                    ? ($n['details']['metrics']['cpu_percent'] ?? null)
                    : ($n['details']['response_ms'] ?? null),
            ])->values()->all(),
            'counts' => [
                'servers' => $servers->count(),
                'servers_ok' => $servers->where('health', 'ok')->count(),
                'sites' => $sites->count(),
                'sites_ok' => $sites->where('health', 'ok')->count(),
                'incidents' => $incidents->count(),
            ],
            'timeline' => $this->timeline($teamId),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** Incidents, deployments and DNS changes of the last 24 hours, oldest first. */
    private function timeline(int $teamId): array
    {
        $from = now()->subDay();
        $events = [];

        Incident::query()->withoutGlobalScopes()->where('team_id', $teamId)
            ->where(fn ($q) => $q->where('opened_at', '>=', $from)->orWhereIn('status', Incident::ACTIVE_STATUSES))
            ->orderBy('opened_at')->limit(100)->get(['id', 'title', 'severity', 'opened_at', 'resolved_at'])
            ->each(function (Incident $i) use (&$events) {
                $events[] = [
                    'kind' => 'incident',
                    'at' => $i->opened_at?->toIso8601String(),
                    'until' => $i->resolved_at?->toIso8601String(),
                    'label' => $i->title,
                    'tone' => Severity::rank($i->severity) >= Severity::rank(Severity::HIGH) ? 'bad' : 'warn',
                    'url' => route('incidents.show', $i->id),
                ];
            });

        Deployment::query()->withoutGlobalScopes()->where('team_id', $teamId)->where('deployed_at', '>=', $from)
            ->orderBy('deployed_at')->limit(100)->get(['application', 'environment', 'version', 'deployed_at'])
            ->each(function (Deployment $d) use (&$events) {
                $events[] = ['kind' => 'deploy', 'at' => $d->deployed_at?->toIso8601String(), 'until' => null, 'label' => trim("{$d->application} {$d->version}"), 'tone' => 'info', 'url' => route('deployments.index')];
            });

        DnsChange::query()
            ->whereIn('domain_id', Domain::query()->withoutGlobalScopes()->where('team_id', $teamId)->select('id'))
            ->where('detected_at', '>=', $from)->with(['domain' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('detected_at')->limit(100)->get()
            ->each(function (DnsChange $c) use (&$events) {
                $events[] = ['kind' => 'dns', 'at' => $c->detected_at?->toIso8601String(), 'until' => null, 'label' => trim(($c->domain?->name ?? '').' '.$c->record_type), 'tone' => 'info', 'url' => $c->domain ? route('domains.show', $c->domain_id) : null];
            });

        usort($events, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));

        return ['from' => $from->toIso8601String(), 'to' => now()->toIso8601String(), 'events' => $events];
    }
}
