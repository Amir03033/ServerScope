<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\Incident;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;

/** The onset, the incident's own timeline and other incidents on the resources in scope. */
final class IncidentCollector implements Collector
{
    private const MAX_OTHERS = 50;

    public function name(): string
    {
        return 'incidents';
    }

    public function collect(EvidenceScope $scope): array
    {
        $incident = $scope->incident;
        $window = $scope->window;
        [$from, $to] = [$window['from'], $window['to']];

        $records = [new EvidenceRecord($window['onset'], 'incident', 'onset', [
            'opened_after_minutes' => $incident->opened_at->ne($window['onset']) ? (int) round($window['onset']->diffInMinutes($window['opened'])) : null,
        ], problem: true, source: ['type' => 'incident', 'id' => (int) $incident->id])];

        foreach ($incident->events()->whereBetween('created_at', [$from, $to])->get() as $event) {
            if ($event->type === 'notified') {
                continue;
            }

            $records[] = new EvidenceRecord(CarbonImmutable::instance($event->created_at), in_array($event->type, ['resolved', 'recovered', 'resource_recovered'], true) ? 'recovery' : 'incident', 'incident_event', ['type' => $event->type],
                detail: $event->message ? mb_strimwidth($event->message, 0, 200, '…') : null, source: ['type' => 'incident_event', 'id' => (int) $event->id]);
        }

        foreach ($scope->resources as $resource) {
            if ($resource['relation'] === 'service' && $resource['depth'] > 1) {
                continue;
            }

            $others = Incident::query()->withoutGlobalScopes()->where('team_id', $scope->teamId())->whereKeyNot($incident->id)
                ->where('subject_type', $resource['type'])->where('subject_id', $resource['model']->getKey())
                ->where(fn ($q) => $q->whereBetween('opened_at', [$from, $to])->orWhereBetween('resolved_at', [$from, $to])
                    ->orWhere(fn ($q) => $q->where('opened_at', '<', $from)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $to))))
                ->limit(self::MAX_OTHERS)->get();

            if ($others->count() >= self::MAX_OTHERS) {
                $scope->truncate($this->name());
            }

            foreach ($others as $other) {
                $url = route('incidents.show', $other);
                $source = ['type' => 'incident', 'id' => (int) $other->id];
                $params = ['id' => $other->id, 'title' => $other->title, 'source' => $other->source, 'severity' => $other->severity, 'started_at' => ($other->started_at ?? $other->opened_at)->toIso8601String()];

                if ($other->opened_at->gte($from)) {
                    $records[] = new EvidenceRecord(CarbonImmutable::instance($other->opened_at), 'incident', 'other_opened', $params, $resource['key'], true, url: $url, source: $source);
                } else {
                    $records[] = new EvidenceRecord($from, 'incident', 'other_ongoing', $params, $resource['key'], true, url: $url, source: $source);
                }

                if ($other->resolved_at !== null && $other->resolved_at->between($from, $to)) {
                    $records[] = new EvidenceRecord(CarbonImmutable::instance($other->resolved_at), 'recovery', 'other_resolved', ['id' => $other->id], $resource['key'], url: $url, source: $source);
                }
            }
        }

        return $records;
    }
}
