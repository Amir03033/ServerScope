<?php

namespace App\Services\Incidents;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\IncidentImpact;
use App\Models\Postmortem;
use App\Services\Dependencies\ResourceCatalog;
use App\Support\Label;
use Carbon\CarbonInterface;

/**
 * The facts of a postmortem, read from what ServerScope already knows:
 * the incident's times, its impact, Incident Memory (cause and fix), its
 * own timeline and the deployments around it. Only the parts people write
 * (summary, impact text, lessons, action items) live in Postmortem.
 *
 * Internal notes (comments), notifications and grouping bookkeeping stay
 * out of the timeline: a postmortem can be shared outside the team.
 */
class PostmortemBuilder
{
    private const TIMELINE_TYPES = ['opened', 'reopened', 'severity_changed', 'acknowledged', 'investigating', 'escalated', 'resource_affected', 'resource_recovered', 'recovered', 'resolved', 'closed'];

    private const MAX_TIMELINE = 40;

    /** Deployments this long before the start are part of the story. */
    private const DEPLOYMENTS_BEFORE_HOURS = 6;

    /** @return array<string, mixed> */
    public function build(Incident $incident, ?Postmortem $postmortem): array
    {
        $incident->loadMissing(['learning.deployment', 'impacts.resource', 'events']);
        $learning = $incident->learning;

        $started = $incident->started_at ?? $incident->opened_at;
        $ended = $incident->resolved_at ?? $incident->closed_at;

        $facts = [
            'started' => $started,
            'detected' => $incident->opened_at,
            'acknowledged' => $incident->acknowledged_at,
            'resolved' => $ended,
            'time_to_detect' => $this->minutes($started, $incident->opened_at),
            'time_to_acknowledge' => $this->minutes($incident->opened_at, $incident->acknowledged_at),
            'duration' => $this->minutes($started, $ended),
            'ongoing' => $ended === null,
        ];

        $impacts = $incident->impacts->sortBy(fn (IncidentImpact $i) => [$i->role !== 'cause', $i->first_failed_at?->getTimestamp() ?? PHP_INT_MAX])
            ->map(fn (IncidentImpact $i) => [
                'name' => ResourceCatalog::name($i->resource),
                'type' => ResourceCatalog::typeLabel($i->resource_type),
                'role' => $i->role,
                'from' => $i->first_failed_at,
                'to' => $i->recovered_at,
                'minutes' => $this->minutes($i->first_failed_at, $i->recovered_at),
            ])->values()->all();

        return [
            'incident' => $incident,
            'subject' => $incident->subjectName(),
            'facts' => $facts,
            'summary' => $postmortem?->summary ?: $this->defaultSummary($incident, $facts),
            'summary_is_default' => blank($postmortem?->summary),
            'impact' => $postmortem?->impact,
            'impacts' => $impacts,
            'cause' => $learning ? [
                'category' => __('incident_memory.categories.'.$learning->cause_category),
                'confirmed' => $learning->isConfirmed(),
                'text' => $learning->root_cause,
                'symptoms' => $learning->symptoms ?? [],
            ] : null,
            'resolution' => $learning?->resolution_steps ?: $incident->resolution_notes,
            'outcome' => $learning?->outcome,
            'deployment' => $learning?->deployment,
            'timeline' => $this->timeline($incident, $started, $ended),
            'went_well' => $postmortem?->went_well,
            'went_wrong' => $postmortem?->went_wrong,
            'action_items' => $postmortem?->actionItems() ?? [],
            'updated' => $postmortem?->updated_at,
            'editor' => $postmortem?->editor?->name,
        ];
    }

    /** "1 h 20 min", "3 days 2 h", or "less than a minute". */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes < 1) {
            return __('postmortems.duration.under_minute');
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        return collect([
            $days ? trans_choice('postmortems.duration.days', $days, ['n' => $days]) : null,
            $hours ? __('postmortems.duration.hours', ['n' => $hours]) : null,
            $rest && ! $days ? __('postmortems.duration.minutes', ['n' => $rest]) : null,
        ])->filter()->implode(' ');
    }

    /** @return array<int, array{at: CarbonInterface, kind: string, title: string, detail: ?string}> */
    private function timeline(Incident $incident, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $rows = $incident->events->whereIn('type', self::TIMELINE_TYPES)->map(fn (IncidentEvent $e) => [
            'at' => $e->created_at,
            'kind' => in_array($e->type, ['recovered', 'resolved', 'closed', 'resource_recovered'], true) ? 'good' : (in_array($e->type, ['opened', 'reopened', 'resource_affected', 'escalated'], true) ? 'bad' : 'step'),
            'title' => Label::of('incident_event', $e->type, IncidentEvent::TYPE_LABELS),
            'detail' => null,
        ])->all();

        if ($incident->started_at && $incident->opened_at && $incident->started_at->lt($incident->opened_at)) {
            $rows[] = ['at' => $incident->started_at, 'kind' => 'bad', 'title' => __('postmortems.timeline.first_failure'), 'detail' => null];
        }

        if ($from) {
            $resources = collect([$incident->subject, ...$incident->impacts->pluck('resource')])->filter()->unique(fn ($r) => $r->getMorphClass().':'.$r->getKey());

            foreach ($resources as $resource) {
                Deployment::query()->forResource($resource)
                    ->whereBetween('deployed_at', [$from->copy()->subHours(self::DEPLOYMENTS_BEFORE_HOURS), $to ?? now()])
                    ->get()
                    ->each(function (Deployment $d) use (&$rows): void {
                        $rows["deploy-{$d->id}"] = ['at' => $d->deployed_at, 'kind' => 'change', 'title' => __('postmortems.timeline.deployment', ['label' => $d->label()]), 'detail' => $d->title];
                    });
            }
        }

        usort($rows, fn ($a, $b) => $a['at'] <=> $b['at']);

        return array_slice(array_values($rows), 0, self::MAX_TIMELINE);
    }

    /** @param  array<string, mixed>  $facts */
    private function defaultSummary(Incident $incident, array $facts): string
    {
        return __($facts['ongoing'] ? 'postmortems.default_summary_ongoing' : 'postmortems.default_summary', [
            'subject' => $incident->subjectName(),
            'title' => $incident->title,
            'duration' => self::duration($facts['duration']),
        ]);
    }

    private function minutes(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from && $to ? max(0, (int) round($from->diffInSeconds($to, true) / 60)) : null;
    }
}
