<?php

namespace App\Services\Reports;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Support\Time;
use Illuminate\Support\Collection;

/**
 * Builds the public view of a status page from live monitoring data.
 *
 * Only data that is safe to publish leaves this class: the admin-chosen
 * component names, derived states, daily availability and (optionally)
 * incident titles. Hostnames, IP addresses, URLs, incident descriptions,
 * notes and diagnostics are never included.
 */
class StatusPagePresenter
{
    public const STATE_RANK = ['operational' => 0, 'unknown' => 1, 'maintenance' => 2, 'degraded' => 3, 'partial_outage' => 4, 'major_outage' => 5];

    public const STATE_LABELS = [
        'operational' => 'Operationeel',
        'degraded' => 'Verminderde prestaties',
        'partial_outage' => 'Gedeeltelijke storing',
        'major_outage' => 'Grote storing',
        'maintenance' => 'Onderhoud',
        'unknown' => 'Onbekend',
    ];

    public function __construct(private UptimeCalculator $uptime) {}

    public function present(StatusPage $page, int $days = 90): array
    {
        $components = $page->components()->with('subject')->get()
            ->filter(fn (StatusPageComponent $c) => $c->subject !== null && ! $c->subject->trashed() && $c->subject->team_id === $page->team_id)
            ->values();

        $rows = $components->map(fn (StatusPageComponent $c) => [
            'id' => $c->id,
            'name' => $c->display_name,
            'description' => $c->description,
            'state' => $this->state($c->subject),
            'uptime' => $page->show_uptime ? $this->daily($c->subject, $days) : null,
        ]);

        $overall = $rows->isEmpty() ? 'unknown' : $rows->sortByDesc(fn ($r) => self::STATE_RANK[$r['state']])->first()['state'];

        return [
            'name' => $page->name,
            'description' => $page->description,
            'accent' => $page->accent_color,
            'overall' => $overall,
            'components' => $rows->all(),
            'incidents' => $this->incidents($page, $components),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function state(Server|Domain $subject): string
    {
        if ($subject instanceof Domain) {
            if (! $subject->is_active) {
                return 'unknown';
            }

            if ($subject->isInMaintenance()) {
                return 'maintenance';
            }

            return match ($subject->status) {
                'healthy' => 'operational',
                'warning' => 'degraded',
                'critical' => $subject->http_status === 'down' || $subject->dns_status === 'failed' ? 'major_outage' : 'partial_outage',
                default => 'unknown',
            };
        }

        $status = $subject->displayStatus();

        if ($status === 'maintenance') {
            return 'maintenance';
        }

        if ($status === 'offline') {
            return 'major_outage';
        }

        if ($status === 'pending') {
            return 'unknown';
        }

        $hasIncident = Incident::query()->active()->where('subject_type', 'server')->where('subject_id', $subject->id)->exists();

        return $hasIncident ? 'degraded' : 'operational';
    }

    /**
     * @return array<int, array{date: string, uptime: ?float}>
     */
    private function daily(Server|Domain $subject, int $days): array
    {
        if ($subject instanceof Domain) {
            return array_map(fn ($b) => ['date' => $b['date'], 'uptime' => $b['uptime']], $this->uptime->dailyBuckets($subject, $days));
        }

        // Servers: share of each day not covered by agent-offline incidents;
        // days before the server existed have no value.
        $zone = Time::zone();
        $start = now($zone)->startOfDay()->subDays($days - 1);
        $incidents = Incident::query()->where('subject_type', 'server')->where('subject_id', $subject->id)->where('source', 'agent')
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start->copy()->utc()))
            ->get(['opened_at', 'resolved_at']);

        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $dayStart = $start->copy()->addDays($i);
            $dayEnd = $dayStart->copy()->addDay()->min(now());

            if ($subject->created_at->gt($dayEnd) || $subject->last_seen_at === null) {
                $result[] = ['date' => $dayStart->format('Y-m-d'), 'uptime' => null];

                continue;
            }

            $from = $subject->created_at->max($dayStart);
            $length = max(1, $from->diffInSeconds($dayEnd, false));
            $down = 0;

            foreach ($incidents as $incident) {
                $s = $incident->opened_at->max($from);
                $e = ($incident->resolved_at ?? now())->min($dayEnd);
                $down += max(0, $s->diffInSeconds($e, false));
            }

            $result[] = ['date' => $dayStart->format('Y-m-d'), 'uptime' => round(max(0, 1 - $down / $length) * 100, 2)];
        }

        return $result;
    }

    private function incidents(StatusPage $page, Collection $components): array
    {
        $names = [];

        foreach ($components as $component) {
            $names[$component->subject_type.':'.$component->subject_id] = $component->display_name;
        }

        if ($names === []) {
            return [];
        }

        return Incident::query()
            ->where('team_id', $page->team_id)
            ->where(function ($q) use ($components) {
                foreach ($components as $c) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $c->subject_type)->where('subject_id', $c->subject_id));
                }
            })
            ->where(fn ($q) => $q->active()->orWhere('resolved_at', '>=', now()->subDays(7)))
            ->whereIn('severity', ['warning', 'high', 'critical'])
            ->latest('opened_at')
            ->limit(20)
            ->get()
            ->map(fn (Incident $i) => [
                'title' => $page->show_incident_titles
                    ? $i->title
                    : __($i->severity === 'critical' ? 'status_page.incident.outage_at' : 'status_page.incident.disruption_at', ['component' => $names[$i->subject_type.':'.$i->subject_id] ?? __('status_page.incident.a_component')]),
                'component' => $names[$i->subject_type.':'.$i->subject_id] ?? null,
                'severity' => $i->severity,
                'status' => $i->isActive() ? ($i->status === 'investigating' ? 'investigating' : ($i->acknowledged_at ? 'identified' : 'detected')) : 'resolved',
                'started_at' => $i->opened_at->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ])
            ->all();
    }
}
