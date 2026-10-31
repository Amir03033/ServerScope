<?php

namespace App\Services\Incidents;

use App\Models\AgentUpdateRequest;
use App\Models\AuditLog;
use App\Models\DnsChange;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MetricSample;
use App\Models\Prediction;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Analytics\MetricHistory;
use App\Services\Analytics\Statistics;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Support\Label;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
 * Output separates evidence (observed facts with their numbers) from
 * possible explanations, which are never presented as causes: something
 * happening before an incident does not prove it caused it.
 */
class TimeMachine
{
    /** Events are shown from this long before the onset. */
    public const BEFORE_HOURS = 6;

    /** ...until this long after recovery (or now). */
    public const AFTER_HOURS = 2;

    /** The investigated period is capped for long-running incidents. */
    public const MAX_SPAN_HOURS = 48;

    /** Hours of history before the onset used as the metric baseline. */
    public const BASELINE_HOURS = 24;

    public const MIN_BASELINE_HOURS = 6;

    /** metric => [minimum absolute change, MAD floor, unit] */
    private const SHIFT_RULES = [
        'cpu_percent' => [15.0, 2.0, '%'],
        'memory_percent' => [10.0, 1.0, '%'],
        'swap_percent' => [10.0, 1.0, '%'],
        'load_1' => [1.0, 0.1, ''],
        'disk_percent' => [5.0, 0.5, '%'],
    ];

    private const MAX_ROWS = 5000;

    private const MAX_EVENTS = 300;

    public function __construct(
        private DependencyGraph $graph,
        private MetricHistory $history,
    ) {}

    /** @return array<string, mixed> */
    public function build(Incident $incident, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $onset = CarbonImmutable::instance($incident->started_at ?? $incident->opened_at);
        $end = CarbonImmutable::instance($incident->resolved_at ?? $now);
        $cappedEnd = $end->min($onset->addHours(self::MAX_SPAN_HOURS));
        $window = [
            'onset' => $onset,
            'opened' => CarbonImmutable::instance($incident->opened_at),
            'end' => $incident->resolved_at ? $end : null,
            'from' => $onset->subHours(self::BEFORE_HOURS),
            'to' => $cappedEnd->addHours(self::AFTER_HOURS)->min($now),
            'truncated' => $cappedEnd->lt($end),
        ];

        $resources = $this->resources($incident);
        $gaps = [];

        if ($resources === []) {
            $gaps[] = __('time_machine.gaps.no_subject');
        } elseif (count($resources) === 1) {
            $gaps[] = __('time_machine.gaps.no_dependencies');
        }

        $events = $this->events($incident, $resources, $window);
        $metrics = [];

        foreach (array_slice(array_values(array_filter($resources, fn ($r) => $r['model'] instanceof Server)), 0, 2) as $resource) {
            $metrics[] = $this->metrics($resource, $window, $gaps);
        }

        if ($metrics === []) {
            $gaps[] = __('time_machine.gaps.no_server');
        }

        $gaps[] = __('time_machine.gaps.no_deployments');

        [$findings, $explanations] = $this->analyse($incident, $events, $metrics, $window);

        return [
            'window' => $window,
            'resources' => $resources,
            'events' => $events,
            'metrics' => $metrics,
            'findings' => $findings,
            'explanations' => $explanations,
            'gaps' => array_values(array_unique($gaps)),
        ];
    }

    /**
     * The incident's resource, what it depends on (up to 3 levels, same
     * team), and the services running on every server among them.
     *
     * @return array<string, array{key: string, type: string, model: Model, label: string, relation: string, depth: int}>
     */
    private function resources(Incident $incident): array
    {
        if ($incident->subject_type === null || ! ResourceCatalog::supports($incident->subject_type)) {
            return [];
        }

        $teamId = (int) $incident->team_id;
        $resources = [];
        $ancestors = $this->graph->ancestors($incident->subject_type, (int) $incident->subject_id, 3, $teamId);

        foreach ($ancestors as $key => $node) {
            $model = ResourceCatalog::find($node['type'], $node['id'], $teamId);

            if ($model !== null) {
                $resources[$key] = ['key' => $key, 'type' => $node['type'], 'model' => $model, 'label' => ResourceCatalog::name($model), 'relation' => $node['depth'] === 0 ? 'subject' : 'upstream', 'depth' => $node['depth']];
            }
        }

        foreach ($resources as $resource) {
            if ($resource['model'] instanceof Server) {
                foreach (ServiceCheck::query()->where('server_id', $resource['model']->id)->get() as $service) {
                    $key = 'service:'.$service->id;
                    $resources[$key] ??= ['key' => $key, 'type' => 'service', 'model' => $service, 'label' => ResourceCatalog::name($service), 'relation' => 'service', 'depth' => $resource['depth'] + 1];
                }
            }
        }

        return $resources;
    }

    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @param  array<string, mixed>  $window
     * @return array<int, array{at: CarbonImmutable, phase: string, category: string, resource: ?string, title: string, detail: ?string, url: ?string, problem: bool}>
     */
    private function events(Incident $incident, array $resources, array $window): array
    {
        $events = [];
        $from = $window['from'];
        $to = $window['to'];
        $add = function (CarbonInterface $at, string $category, ?string $resource, string $title, ?string $detail = null, ?string $url = null, bool $problem = false) use (&$events, $window): void {
            $at = CarbonImmutable::instance($at);
            $phase = $at->lt($window['onset']) ? 'before' : ($window['end'] !== null && $at->gt($window['end']) ? 'after' : 'during');
            $events[] = compact('at', 'phase', 'category', 'resource', 'title', 'detail', 'url', 'problem');
        };

        // The incident's own timeline.
        $add($window['onset'], 'incident', null, __('time_machine.events.onset'), $incident->opened_at->ne($window['onset']) ? __('time_machine.events.onset_detail', ['minutes' => (int) round($window['onset']->diffInMinutes($window['opened']))]) : null, problem: true);

        foreach ($incident->events()->whereBetween('created_at', [$from, $to])->get() as $event) {
            if ($event->type === 'notified') {
                continue;
            }

            $add($event->created_at, in_array($event->type, ['resolved', 'recovered', 'resource_recovered'], true) ? 'recovery' : 'incident', null, Label::of('incident_event', $event->type, IncidentEvent::TYPE_LABELS), $event->message ? mb_strimwidth($event->message, 0, 200, '…') : null);
        }

        $byType = [];

        foreach ($resources as $resource) {
            $byType[$resource['type']][$resource['model']->getKey()] = $resource;
        }

        // Other incidents on the same or upstream resources.
        foreach ($resources as $resource) {
            if ($resource['relation'] === 'service' && $resource['depth'] > 1) {
                continue;
            }

            $others = Incident::query()->whereKeyNot($incident->id)
                ->where('subject_type', $resource['type'])->where('subject_id', $resource['model']->getKey())
                ->where(fn ($q) => $q->whereBetween('opened_at', [$from, $to])->orWhereBetween('resolved_at', [$from, $to])
                    ->orWhere(fn ($q) => $q->where('opened_at', '<', $from)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $to))))
                ->limit(50)->get();

            foreach ($others as $other) {
                $url = route('incidents.show', $other);

                if ($other->opened_at->gte($from)) {
                    $add($other->opened_at, 'incident', $resource['label'], __('time_machine.events.other_opened', ['id' => $other->id, 'title' => $other->title]), null, $url, true);
                } else {
                    $add($from, 'incident', $resource['label'], __('time_machine.events.other_ongoing', ['id' => $other->id, 'title' => $other->title]), null, $url, true);
                }

                if ($other->resolved_at !== null && $other->resolved_at->between($from, $to)) {
                    $add($other->resolved_at, 'recovery', $resource['label'], __('time_machine.events.other_resolved', ['id' => $other->id]), null, $url);
                }
            }
        }

        // Service state transitions.
        if (isset($byType['service'])) {
            $rows = DB::table('service_check_events')->whereIn('service_check_id', array_keys($byType['service']))
                ->whereBetween('recorded_at', [$from, $to])->orderBy('recorded_at')->limit(self::MAX_ROWS)->get();

            foreach ($rows as $row) {
                $failing = in_array($row->status, ['stopped', 'failed', 'not_found', 'stale'], true);
                $add(CarbonImmutable::parse($row->recorded_at, 'UTC'), $failing ? 'service' : 'recovery', $byType['service'][$row->service_check_id]['label'], __('time_machine.events.service', ['from' => $row->previous_status ?? '—', 'to' => $row->status]), $row->detail, null, $failing);
            }
        }

        // Domain checks (HTTP, TLS, DNS, expiry) and page checks: only
        // changes between success and failure, not every result.
        if (isset($byType['domain'])) {
            $rows = DB::table('domain_checks')->whereIn('domain_id', array_keys($byType['domain']))
                ->whereBetween('checked_at', [$from, $to])->orderBy('checked_at')->limit(self::MAX_ROWS)
                ->get(['domain_id', 'type', 'success', 'message', 'error_code', 'checked_at']);
            $this->transitions($rows, fn ($r) => $r->domain_id.':'.$r->type, function ($row, bool $failing) use ($add, $byType) {
                $add(CarbonImmutable::parse($row->checked_at, 'UTC'), $failing ? 'check' : 'recovery', $byType['domain'][$row->domain_id]['label'], __($failing ? 'time_machine.events.check_failed' : 'time_machine.events.check_recovered', ['type' => strtoupper((string) $row->type)]), $failing ? ($row->message ?? $row->error_code) : null, null, $failing);
            });

            foreach (DnsChange::query()->whereIn('domain_id', array_keys($byType['domain']))->whereBetween('detected_at', [$from, $to])->limit(200)->get() as $change) {
                $add($change->detected_at, 'change', $byType['domain'][$change->domain_id]['label'], __('time_machine.events.dns_changed', ['type' => $change->record_type]));
            }
        }

        if (isset($byType['page'])) {
            $rows = DB::table('page_checks')->whereIn('monitored_page_id', array_keys($byType['page']))
                ->whereBetween('checked_at', [$from, $to])->orderBy('checked_at')->limit(self::MAX_ROWS)
                ->get(['monitored_page_id', 'success', 'failure_type', 'message', 'checked_at']);
            $this->transitions($rows, fn ($r) => (string) $r->monitored_page_id, function ($row, bool $failing) use ($add, $byType) {
                $add(CarbonImmutable::parse($row->checked_at, 'UTC'), $failing ? 'check' : 'recovery', $byType['page'][$row->monitored_page_id]['label'], __($failing ? 'time_machine.events.check_failed' : 'time_machine.events.check_recovered', ['type' => __('time_machine.page')]), $failing ? ($row->message ?? $row->failure_type) : null, null, $failing);
            });
        }

        // Anomalies and forecasts detected by the hourly analysis.
        foreach ($resources as $resource) {
            if (! in_array($resource['type'], ['server', 'domain'], true)) {
                continue;
            }

            $predictions = Prediction::query()->forResource($resource['model'])->whereBetween('detected_at', [$from, $to])->limit(50)->get();

            foreach ($predictions as $prediction) {
                $add($prediction->detected_at, 'anomaly', $resource['label'], __($prediction->kind === 'anomaly' ? 'time_machine.events.anomaly' : 'time_machine.events.forecast', ['metric' => $prediction->metric.($prediction->dimension ? " ({$prediction->dimension})" : '')]), mb_strimwidth((string) $prediction->reason, 0, 200, '…'), null, $prediction->kind === 'anomaly');
            }
        }

        // Changes: agent updates and audited configuration changes.
        if (isset($byType['server'])) {
            foreach (AgentUpdateRequest::query()->whereIn('server_id', array_keys($byType['server']))->whereNotNull('finished_at')->whereBetween('finished_at', [$from, $to])->limit(50)->get() as $update) {
                $add($update->finished_at, 'change', $byType['server'][$update->server_id]['label'], __('time_machine.events.agent_update', ['from' => $update->from_version ?? '—', 'to' => $update->target_version, 'status' => $update->status]));
            }
        }

        foreach ($byType as $type => $items) {
            if (in_array($type, ['server', 'domain', 'page'], true)) {
                foreach (AuditLog::query()->where('auditable_type', $type)->whereIn('auditable_id', array_keys($items))->whereBetween('created_at', [$from, $to])->limit(100)->get() as $log) {
                    $add($log->created_at, 'change', $items[$log->auditable_id]['label'], $log->description);
                }
            }
        }

        $order = ['incident' => 0, 'change' => 1, 'anomaly' => 2, 'service' => 3, 'check' => 4, 'recovery' => 5];
        usort($events, fn ($a, $b) => [$a['at']->getTimestamp(), $order[$a['category']] ?? 9] <=> [$b['at']->getTimestamp(), $order[$b['category']] ?? 9]);

        return array_slice($events, 0, self::MAX_EVENTS);
    }

    /** Calls $emit for each flip between success and failure per group (a failure at the start counts too). */
    private function transitions(iterable $rows, callable $group, callable $emit): void
    {
        $last = [];

        foreach ($rows as $row) {
            $key = $group($row);
            $success = (bool) $row->success;

            if (($last[$key] ?? true) !== $success) {
                $emit($row, ! $success);
            }

            $last[$key] = $success;
        }
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
        $hourly = $this->history->buckets($server, $onset->subHours(self::BASELINE_HOURS + 1), $window['to'], 3600);

        $detailFrom = $onset->subHour();
        $detailTo = ($window['end'] ?? $window['to'])->addMinutes(30)->min($window['to'])->min($onset->addHours(6));
        $raw = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$detailFrom, $detailTo])->orderBy('collected_at')
            ->limit(2000)->get(['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes']);

        if ($hourly === [] && $raw->isEmpty()) {
            $gaps[] = __('time_machine.gaps.no_metrics', ['server' => $resource['label']]);
        } elseif ($raw->isEmpty()) {
            $gaps[] = __('time_machine.gaps.no_raw', ['server' => $resource['label']]);
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
            'detail' => $raw->isEmpty() ? [] : $detail,
            'shifts' => $this->shifts($server, $hourly, $onset),
        ];
    }

    /**
     * Metrics whose value in the hour before the onset differs clearly
     * from the server's own previous 24 hours (robust: median and MAD,
     * plus a minimum absolute change, as in anomaly detection).
     *
     * @return array<int, array{metric: string, before: float, baseline: float, change: float, unit: string, hours: int}>
     */
    private function shifts(Server $server, array $hourly, CarbonImmutable $onset): array
    {
        $onsetHour = intdiv($onset->getTimestamp(), 3600) * 3600;
        $recent = MetricSample::query()->where('server_id', $server->id)
            ->whereBetween('collected_at', [$onset->subHour(), $onset])
            ->get(['collected_at', 'cpu_percent', 'load_1', 'memory_total_bytes', 'memory_used_bytes', 'swap_total_bytes', 'swap_used_bytes', 'disks']);
        $shifts = [];

        foreach ($hourly as $key => $hours) {
            [$metric, $dimension] = array_pad(explode(':', $key, 2), 2, null);

            if (! isset(self::SHIFT_RULES[$metric])) {
                continue;
            }

            [$minimum, $floor, $unit] = self::SHIFT_RULES[$metric];
            $baseline = array_values(array_map(fn ($b) => $b['mean'], array_filter($hours, fn ($h) => $h < $onsetHour - 3600, ARRAY_FILTER_USE_KEY)));

            if (count($baseline) < self::MIN_BASELINE_HOURS) {
                continue;
            }

            // The hour before the onset: raw samples when kept, else the
            // hourly mean of the hour the onset falls in or the one before.
            $values = $recent->map(fn (MetricSample $s) => $this->value($s, $metric, $dimension))->filter(fn ($v) => $v !== null);
            $before = $values->count() >= 5 ? (float) $values->avg() : ($hours[$onsetHour - 3600]['mean'] ?? $hours[$onsetHour]['mean'] ?? null);

            if ($before === null) {
                continue;
            }

            $median = Statistics::median($baseline);
            $mad = Statistics::mad($baseline);
            $change = $before - $median;

            if (abs($change) >= $minimum && abs(Statistics::robustZ($before, $median, $mad, $floor)) >= 3.5) {
                $shifts[] = ['metric' => $key, 'before' => round($before, 2), 'baseline' => round($median, 2), 'change' => round($change, 2), 'unit' => $unit, 'hours' => count($baseline)];
            }
        }

        return $shifts;
    }

    private function value(MetricSample $sample, string $metric, ?string $dimension): ?float
    {
        return match ($metric) {
            'cpu_percent' => $sample->cpu_percent,
            'load_1' => $sample->load_1,
            'memory_percent' => $sample->memory_total_bytes > 0 && $sample->memory_used_bytes !== null ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100 : null,
            'swap_percent' => $sample->swap_total_bytes > 0 && $sample->swap_used_bytes !== null ? $sample->swap_used_bytes / $sample->swap_total_bytes * 100 : null,
            'disk_percent' => collect((array) $sample->disks)->firstWhere('path', $dimension)['percent'] ?? null,
            default => null,
        };
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
