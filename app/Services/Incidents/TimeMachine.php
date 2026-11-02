<?php

namespace App\Services\Incidents;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Incidents\Evidence\Collectors;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use App\Services\Incidents\Evidence\MetricEvidence;
use App\Services\Incidents\Evidence\ScopeResolver;
use App\Support\Label;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time Machine: what happened before, during and after an incident.
 *
 * Reads only data ServerScope already stores, within the incident's team:
 * the incident's own timeline, other incidents, check results, service
 * transitions, DNS changes, anomalies/forecasts, agent updates and audited
 * configuration changes of the incident's resource and of what it depends
 * on (confirmed and structural dependencies, the same edges correlation
 * uses). Metric history comes from MetricHistory (hourly rollups for
 * aggregated hours, raw samples for recent ones) plus raw samples around
 * the onset while retention still keeps them.
 *
 * Collection is shared with NEXUS Intelligence (App\Services\Incidents\
 * Evidence): the collectors emit codes, which are rendered here.
 *
 * Output separates evidence (observed facts with their numbers) from
 * possible explanations, which are never presented as causes: something
 * happening before an incident does not prove it caused it.
 */
class TimeMachine
{
    /** Events are shown from this long before the onset. */
    public const BEFORE_HOURS = ScopeResolver::BEFORE_HOURS;

    /** ...until this long after recovery (or now). */
    public const AFTER_HOURS = ScopeResolver::AFTER_HOURS;

    /** The investigated period is capped for long-running incidents. */
    public const MAX_SPAN_HOURS = ScopeResolver::MAX_SPAN_HOURS;

    /** Hours of history before the onset used as the metric baseline. */
    public const BASELINE_HOURS = MetricEvidence::BASELINE_HOURS;

    public const MIN_BASELINE_HOURS = MetricEvidence::MIN_BASELINE_HOURS;

    private const MAX_EVENTS = 300;

    public function __construct(
        private ScopeResolver $scope,
        private Collectors $collectors,
        private MetricEvidence $metricEvidence,
    ) {}

    /** @return array<string, mixed> */
    public function build(Incident $incident, ?CarbonInterface $now = null): array
    {
        $window = $this->scope->window($incident, $now ?? now());
        $resources = $this->scope->resources($incident);
        $gaps = ScopeResolver::gaps($resources);

        $scope = new EvidenceScope($incident, $resources, $window);
        $events = $this->events($scope);
        $metrics = [];

        foreach (array_slice(array_values(array_filter($resources, fn ($r) => $r['model'] instanceof Server)), 0, 2) as $resource) {
            $metrics[] = $this->metrics($resource, $window, $gaps);
        }

        if ($metrics === []) {
            $gaps[] = ['code' => 'no_server', 'params' => []];
        }

        if (! Deployment::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->exists()) {
            $gaps[] = ['code' => 'no_deployments', 'params' => []];
        }

        [$findings, $explanations] = $this->analyse($incident, $events, $metrics, $window);

        return [
            'window' => $window,
            'resources' => $resources,
            'events' => $events,
            'metrics' => $metrics,
            'findings' => $findings,
            'explanations' => $explanations,
            'gaps' => array_values(array_unique(array_map(fn (array $g) => __('time_machine.gaps.'.$g['code'], $g['params']), $gaps))),
        ];
    }

    /**
     * The collected evidence as timeline rows, in order.
     *
     * @return array<int, array{at: CarbonImmutable, phase: string, category: string, resource: ?string, title: string, detail: ?string, url: ?string, problem: bool}>
     */
    private function events(EvidenceScope $scope): array
    {
        $events = array_map(fn (EvidenceRecord $r) => [
            'at' => $r->at,
            'phase' => $scope->phase($r->at),
            'category' => $r->category,
            'resource' => $r->resource !== null ? $scope->resources[$r->resource]['label'] : null,
            'title' => self::title($r),
            'detail' => $r->code === 'onset'
                ? ($r->params['opened_after_minutes'] !== null ? __('time_machine.events.onset_detail', ['minutes' => $r->params['opened_after_minutes']]) : null)
                : $r->detail,
            'url' => $r->url,
            'problem' => $r->problem,
        ], $this->collectors->collect($scope));

        $order = ['incident' => 0, 'change' => 1, 'anomaly' => 2, 'service' => 3, 'check' => 4, 'recovery' => 5];
        usort($events, fn ($a, $b) => [$a['at']->getTimestamp(), $order[$a['category']] ?? 9] <=> [$b['at']->getTimestamp(), $order[$b['category']] ?? 9]);

        return array_slice($events, 0, self::MAX_EVENTS);
    }

    /** The timeline sentence of an evidence record, in the current language. */
    public static function title(EvidenceRecord $r): string
    {
        $p = $r->params;

        return match ($r->code) {
            'onset' => __('time_machine.events.onset'),
            'incident_event' => Label::of('incident_event', $p['type'], IncidentEvent::TYPE_LABELS),
            'other_opened', 'other_ongoing' => __('time_machine.events.'.$r->code, ['id' => $p['id'], 'title' => $p['title']]),
            'other_resolved' => __('time_machine.events.other_resolved', ['id' => $p['id']]),
            'service' => __('time_machine.events.service', ['from' => $p['from'] ?? '—', 'to' => $p['to']]),
            'check_failed', 'check_recovered' => __('time_machine.events.'.$r->code, ['type' => $p['type'] === 'page' ? __('time_machine.page') : strtoupper((string) $p['type'])]),
            'dns_changed' => __('time_machine.events.dns_changed', ['type' => $p['type']]),
            'anomaly', 'forecast' => __('time_machine.events.'.$r->code, ['metric' => $p['metric'].($p['dimension'] ? " ({$p['dimension']})" : '')]),
            'agent_update' => __('time_machine.events.agent_update', ['from' => $p['from'] ?? '—', 'to' => $p['to'], 'status' => $p['status']]),
            'deployment' => __('deployments.timeline', ['label' => $p['label'], 'environment' => $p['environment']]),
            'audit' => (string) $p['description'],
            default => $r->code,
        };
    }

    /**
     * Hourly context (24 hours before the onset to the end of the window)
     * from MetricHistory, and minute detail from raw samples around the
     * onset while retention still keeps them.
     *
     * @return array<string, mixed>
     */
    private function metrics(array $resource, array $window, array &$gaps): array
    {
        /** @var Server $server */
        $server = $resource['model'];
        $onset = $window['onset'];
        $hourly = $this->metricEvidence->hourly($server, $onset, $window['to']);

        $detailFrom = $onset->subHour();
        $detailTo = ($window['end'] ?? $window['to'])->addMinutes(30)->min($window['to'])->min($onset->addHours(6));
        $raw = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$detailFrom, $detailTo])->orderBy('collected_at')
            ->limit(2000)->get(['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes']);

        if ($hourly === [] && $raw->isEmpty()) {
            $gaps[] = ['code' => 'no_metrics', 'params' => ['server' => $resource['label']]];
        } elseif ($raw->isEmpty()) {
            $gaps[] = ['code' => 'no_raw', 'params' => ['server' => $resource['label']]];
        }

        $inIncident = fn (int $ts) => $ts + 3600 > $onset->getTimestamp() && ($window['end'] === null || $ts <= $window['end']->getTimestamp());
        $series = [];

        foreach (['cpu_percent', 'memory_percent', 'load_1'] as $metric) {
            $points = [];

            for ($hour = intdiv($onset->subHours(self::BASELINE_HOURS)->getTimestamp(), 3600) * 3600; $hour < $window['to']->getTimestamp(); $hour += 3600) {
                $points[] = ['label' => Time::format(CarbonImmutable::createFromTimestampUTC($hour), 'd-m H:i'), 'value' => isset($hourly[$metric][$hour]) ? round($hourly[$metric][$hour]['mean'], 2) : null, 'flag' => $inIncident($hour)];
            }

            $series[$metric] = $points;
        }

        // Deployments of this server on the hourly charts.
        $firstHour = intdiv($onset->subHours(self::BASELINE_HOURS)->getTimestamp(), 3600) * 3600;
        $markers = Deployment::query()->withoutGlobalScopes()->where('team_id', $server->team_id)->forResource($server)
            ->whereBetween('deployed_at', [CarbonImmutable::createFromTimestampUTC($firstHour), $window['to']])->limit(20)->get()
            ->map(fn (Deployment $d) => ['index' => intdiv($d->deployed_at->getTimestamp() - $firstHour, 3600), 'label' => __('deployments.chart_marker').' '.$d->label().' · '.Time::format($d->deployed_at, 'd-m H:i')])->all();

        $step = max(1, (int) ceil($raw->count() / 240));
        $detail = ['cpu_percent' => [], 'memory_percent' => []];

        foreach ($raw->values() as $i => $sample) {
            if ($i % $step !== 0) {
                continue;
            }

            $label = Time::format($sample->collected_at, 'H:i');
            $flag = $sample->collected_at->gte($onset) && ($window['end'] === null || $sample->collected_at->lte($window['end']));
            $detail['cpu_percent'][] = ['label' => $label, 'value' => $sample->cpu_percent, 'flag' => $flag];
            $detail['memory_percent'][] = ['label' => $label, 'value' => $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null ? round($sample->memory_used_bytes / $sample->memory_total_bytes * 100, 1) : null, 'flag' => $flag];
        }

        return [
            'server' => $server,
            'label' => $resource['label'],
            'relation' => $resource['relation'],
            'hourly' => $series,
            'markers' => $markers,
            'detail' => $raw->isEmpty() ? [] : $detail,
            'shifts' => $this->metricEvidence->shifts($server, $hourly, $onset),
        ];
    }

    /**
     * Evidence (what was observed) and possible explanations (what it
     * might mean). Explanations are ordered by how directly the evidence
     * relates (a confirmed dependency first) and always carry a caveat.
     *
     * @return array{0: array<int, array{text: string, kind: string}>, 1: array<int, array{text: string, basis: string}>}
     */
    private function analyse(Incident $incident, array $events, array $metrics, array $window): array
    {
        $findings = [];
        $explanations = [];
        $minutes = fn (CarbonImmutable $at) => max(0, (int) round($at->diffInMinutes($window['onset'], true)));

        foreach ($metrics as $m) {
            foreach ($m['shifts'] as $shift) {
                $text = __('time_machine.findings.shift', ['metric' => $shift['metric'], 'server' => $m['label'], 'before' => $shift['before'].$shift['unit'], 'baseline' => $shift['baseline'].$shift['unit'], 'hours' => $shift['hours']]);
                $findings[] = ['text' => $text, 'kind' => 'metric'];
                $explanations[] = ['rank' => $m['relation'] === 'subject' ? 3 : 4, 'text' => __('time_machine.explanations.pressure', ['metric' => $shift['metric'], 'server' => $m['label']]), 'basis' => $text];
            }
        }

        $seen = [];

        foreach ($events as $event) {
            if ($event['phase'] !== 'before' || ! in_array($event['category'], ['incident', 'service', 'check', 'change', 'anomaly'], true) || ($event['category'] !== 'change' && ! $event['problem'])) {
                continue;
            }

            $text = __('time_machine.findings.preceded', ['minutes' => $minutes($event['at']), 'resource' => $event['resource'] ?? '—', 'event' => $event['title']]);

            if (isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $findings[] = ['text' => $text, 'kind' => $event['category']];
            $explanations[] = match ($event['category']) {
                'incident', 'service' => ['rank' => 1, 'text' => __('time_machine.explanations.upstream', ['resource' => $event['resource'] ?? '—']), 'basis' => $text],
                'change' => ['rank' => 2, 'text' => __('time_machine.explanations.change', ['resource' => $event['resource'] ?? '—']), 'basis' => $text],
                default => ['rank' => 3, 'text' => __('time_machine.explanations.signal', ['resource' => $event['resource'] ?? '—']), 'basis' => $text],
            };
        }

        if ($incident->parent_id !== null) {
            $findings[] = ['text' => __('time_machine.findings.grouped', ['id' => $incident->parent_id]), 'kind' => 'incident'];
        }

        usort($explanations, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        // One explanation per text, keeping its strongest basis.
        $unique = [];

        foreach ($explanations as $e) {
            $unique[$e['text']] ??= ['text' => $e['text'], 'basis' => $e['basis']];
        }

        return [$findings, array_slice(array_values($unique), 0, 8)];
    }
}
