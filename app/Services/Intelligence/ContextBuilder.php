<?php

namespace App\Services\Intelligence;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Incidents\CorrelationEngine;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\Collectors;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use App\Services\Incidents\Evidence\MetricEvidence;
use App\Services\Incidents\Evidence\ScopeResolver;
use App\Services\Incidents\IncidentEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the evidence snapshot of an investigation from the collectors
 * Time Machine uses (same scope, window and rows) plus metric coverage,
 * check summaries, maintenance windows, grouping and, for silent agents,
 * the checks of other websites on that server.
 *
 * Every item: key (E…), kind, category, code, params, at, phase, problem,
 * resource ("type:id" in scope), source ({type, id} of the stored row) and
 * detail (untrusted monitoring text, scrubbed of secrets and shortened).
 */
class ContextBuilder
{
    /** Absolute levels in the hour before the onset that count as exhaustion. */
    public const HIGH_LEVELS = ['cpu_percent' => 90.0, 'memory_percent' => 95.0, 'swap_percent' => 80.0, 'disk_percent' => 95.0];

    private const MAX_SERVERS = 3;

    private const KIND_ORDER = ['event' => 0, 'metric' => 1, 'coverage' => 2, 'checks' => 3, 'maintenance' => 4, 'correlation' => 5];

    public function __construct(
        private ScopeResolver $scopes,
        private Collectors $collectors,
        private MetricEvidence $metrics,
        private DependencyGraph $graph,
        private ImpactAnalyzer $impact,
    ) {}

    public function build(Incident $incident, CarbonImmutable $asOf): InvestigationContext
    {
        $teamId = (int) $incident->team_id;
        $window = $this->scopes->window($incident, $asOf);
        $resources = $this->scopes->resources($incident);
        $truncated = [];

        if (count($resources) > (int) config('serverscope.intelligence.max_resources', 150)) {
            $resources = array_slice($resources, 0, (int) config('serverscope.intelligence.max_resources', 150), true);
            $truncated[] = 'scope';
        }

        $gaps = array_map(fn ($g) => ['code' => $g['code'], 'params' => $g['params']], ScopeResolver::gaps($resources));
        $collectors = [];
        $scope = new EvidenceScope($incident, $resources, $window);

        $records = $this->collectors->collect($scope, function (Collector $collector, Throwable $e) use (&$collectors, &$gaps, $incident) {
            $collectors[$collector->name()] = 'failed';
            $gaps[] = ['code' => 'collector_failed', 'params' => ['collector' => $collector->name()]];
            // The exception text stays in the log; the snapshot only names the collector.
            Log::warning('Investigation collector failed', ['incident' => $incident->id, 'collector' => $collector->name(), 'exception' => $e::class]);
        });

        foreach ($this->collectors->all() as $collector) {
            $collectors[$collector->name()] ??= 'ok';
        }

        $truncated = [...$truncated, ...$scope->truncated];
        $items = array_map(fn (EvidenceRecord $r) => $this->fromRecord($r, $scope), $records);

        // Metrics of the servers in scope (the incident's own first).
        $servers = array_slice(array_values(array_filter($resources, fn ($r) => $r['model'] instanceof Server)), 0, self::MAX_SERVERS);

        if ($servers === []) {
            $gaps[] = ['code' => 'no_server', 'params' => []];
        }

        $collectors['metrics'] = 'ok';

        foreach ($servers as $resource) {
            try {
                array_push($items, ...$this->metricItems($resource, $window, $asOf, $gaps, $truncated));
            } catch (Throwable $e) {
                $collectors['metrics'] = 'failed';
                $gaps[] = ['code' => 'collector_failed', 'params' => ['collector' => 'metrics']];
                Log::warning('Investigation collector failed', ['incident' => $incident->id, 'collector' => 'metrics', 'exception' => $e::class]);
            }
        }

        foreach ($scope->checks as $key => $phases) {
            $items[] = $this->item('checks', null, 'check_summary', self::flatten($phases), null, 'during', false, $key);
        }

        $collectors['maintenance'] = 'ok';
        array_push($items, ...$this->maintenanceItems($resources, $window));
        array_push($items, ...$this->correlationItems($incident));

        // Other websites on servers whose agent went silent.
        foreach ($this->silentServers($incident, $items) as $server) {
            foreach ($this->impact->websitesOn($teamId, $server, $window['onset'], $window['end'] ?? $window['to'], array_filter([$incident->subject_type ? "{$incident->subject_type}:{$incident->subject_id}" : null]), 20) as $key => $o) {
                $items[] = $this->item('checks', null, 'dependent_checks', ['server' => $server, 'ok' => $o['ok'], 'failed' => $o['failed']], null, 'during', $o['failed'] > 0, $key);
            }
        }

        $items = $this->order($items, $window);
        $max = max(20, (int) config('serverscope.intelligence.max_evidence', 300));

        if (count($items) > $max) {
            // Problems, changes and summaries first; ordinary events as room allows.
            $keep = collect($items)->sortBy(fn ($i, $n) => [$i['problem'] || $i['category'] === 'change' || $i['kind'] !== 'event' ? 0 : 1, $n])->take($max)->keys()->all();
            sort($keep);
            $items = array_values(array_intersect_key($items, array_flip($keep)));
            $truncated[] = 'evidence';
        }

        $evidence = [];

        foreach ($items as $n => $item) {
            $key = 'E'.($n + 1);
            $evidence[$key] = ['key' => $key, ...$item];
        }

        return new InvestigationContext(
            incident: [
                'id' => (int) $incident->id,
                'source' => $incident->source,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'subject' => $incident->subject_type && $incident->subject_id ? "{$incident->subject_type}:{$incident->subject_id}" : null,
                'failure_kind' => CorrelationEngine::failureKind($incident),
                'parent_id' => $incident->parent_id,
                'cause_incident_id' => $incident->correlation['cause_incident_id'] ?? null,
            ],
            window: $window,
            resources: $this->resourceRows($incident, $resources),
            evidence: $evidence,
            gaps: array_values(array_unique($gaps, SORT_REGULAR)),
            collectors: $collectors,
            truncated: array_values(array_unique($truncated)),
        );
    }

    /** @return array<string, mixed> */
    private function fromRecord(EvidenceRecord $r, EvidenceScope $scope): array
    {
        $params = array_map(fn ($v) => is_string($v) ? mb_strimwidth($v, 0, 200, '…') : $v, $r->params);

        return $this->item('event', $r->category, $r->code, $params, $r->at, $scope->phase($r->at), $r->problem, $r->resource, $r->source, $r->detail);
    }

    /** @return array<string, mixed> */
    private function item(string $kind, ?string $category, string $code, array $params, ?CarbonImmutable $at, string $phase, bool $problem, ?string $resource, ?array $source = null, ?string $detail = null): array
    {
        return [
            'kind' => $kind,
            'category' => $category ?? $kind,
            'code' => $code,
            'params' => $params,
            'at' => $at?->toIso8601String(),
            'phase' => $phase,
            'problem' => $problem,
            'resource' => $resource,
            'source' => $source,
            'detail' => IncidentEvidence::clean($detail, 200),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function metricItems(array $resource, array $window, CarbonImmutable $asOf, array &$gaps, array &$truncated): array
    {
        /** @var Server $server */
        $server = $resource['model'];
        $key = $resource['key'];
        $onset = $window['onset'];
        $items = [];
        $hourly = $this->metrics->hourly($server, $onset, $window['to']);
        $coverage = $this->metrics->coverage($server, $window, $asOf);

        if ($hourly === [] && $coverage['samples_before'] + $coverage['samples_during'] === 0) {
            $gaps[] = ['code' => 'no_metrics', 'params' => ['resource' => $key]];
        } elseif ($coverage['samples_before'] + $coverage['samples_during'] === 0) {
            $gaps[] = ['code' => 'no_raw', 'params' => ['resource' => $key]];
        }

        if ($coverage['truncated']) {
            $truncated[] = 'metrics';
        }

        if ($coverage['future_samples'] > 0) {
            $gaps[] = ['code' => 'future_samples', 'params' => ['resource' => $key, 'count' => $coverage['future_samples']]];
        }

        // The level in the hour before the onset: the same value the shift
        // uses (raw samples when kept), else the hourly mean of that hour.
        $levels = [];

        foreach ($this->metrics->shifts($server, $hourly, $onset) as $shift) {
            $items[] = $this->item('metric', 'metric', 'metric_shift', $shift, $onset, 'before', true, $key);
            $levels[$shift['metric']] = $shift['before'];
        }

        $hour = intdiv($onset->getTimestamp(), 3600) * 3600 - 3600;

        foreach ($hourly as $metric => $buckets) {
            $levels[$metric] ??= $buckets[$hour]['mean'] ?? null;
        }

        foreach ($levels as $metric => $value) {
            $base = explode(':', $metric, 2)[0];

            if (isset(self::HIGH_LEVELS[$base]) && $value !== null && $value >= self::HIGH_LEVELS[$base]) {
                $items[] = $this->item('metric', 'metric', 'metric_high', ['metric' => $metric, 'value' => round($value, 1), 'threshold' => self::HIGH_LEVELS[$base]], $onset, 'before', true, $key);
            }
        }

        $duration = $onset->diffInMinutes($window['end'] ?? $window['to'], true);
        $silent = $coverage['samples_during'] === 0 && $duration >= 5;
        $items[] = $this->item('coverage', 'coverage', $silent ? 'agent_silent' : 'agent_reporting', [
            'samples_before' => $coverage['samples_before'],
            'samples_during' => $coverage['samples_during'],
            'max_gap_seconds' => $coverage['max_gap_seconds'],
            'last_before' => $coverage['last_at'],
        ], $onset, 'during', $silent, $key);

        foreach ($coverage['reboots'] as $at) {
            $items[] = $this->item('metric', 'change', 'reboot', [], CarbonImmutable::parse($at), $onset->gt(CarbonImmutable::parse($at)) ? 'before' : 'during', true, $key);
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function maintenanceItems(array $resources, array $window): array
    {
        $items = [];

        foreach ($resources as $resource) {
            $model = $resource['model'];

            if (! ($model instanceof Server || $model instanceof Domain) || $model->maintenance_starts_at === null) {
                continue;
            }

            $start = CarbonImmutable::instance($model->maintenance_starts_at);
            $end = $model->maintenance_ends_at ? CarbonImmutable::instance($model->maintenance_ends_at) : null;

            // Only the current or last window is stored; it counts when it overlaps the period.
            if ($start->lte($window['to']) && ($end === null || $end->gte($window['from']))) {
                $items[] = $this->item('maintenance', 'change', 'maintenance', ['ends_at' => $end?->toIso8601String()], $start, $start->lt($window['onset']) ? 'before' : 'during', false, $resource['key']);
            }
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function correlationItems(Incident $incident): array
    {
        if ($incident->parent_id === null) {
            return [];
        }

        $c = $incident->correlation ?? [];

        return [$this->item('correlation', 'incident', 'grouped_under', [
            'parent_id' => (int) $incident->parent_id,
            'cause_incident_id' => isset($c['cause_incident_id']) ? (int) $c['cause_incident_id'] : null,
            'reason_code' => $c['reason_code'] ?? null,
            'failure_kind' => $c['failure_kind'] ?? null,
        ], isset($c['correlated_at']) ? CarbonImmutable::parse($c['correlated_at']) : null, 'during', false, null, ['type' => 'incident', 'id' => (int) $incident->parent_id])];
    }

    /** Servers in scope whose agent was silent: the incident's own (agent incident) or upstream ones with an agent incident. @return array<int, string> */
    private function silentServers(Incident $incident, array $items): array
    {
        $servers = [];

        if ($incident->source === 'agent' && $incident->subject_type === 'server') {
            $servers[] = 'server:'.$incident->subject_id;
        }

        foreach ($items as $item) {
            if (in_array($item['code'], ['other_opened', 'other_ongoing'], true) && ($item['params']['source'] ?? null) === 'agent' && str_starts_with((string) $item['resource'], 'server:')) {
                $servers[] = $item['resource'];
            }
        }

        return array_slice(array_values(array_unique($servers)), 0, self::MAX_SERVERS);
    }

    /** Chronological; items without a time after the timed ones; stable within equal times. */
    private function order(array $items, array $window): array
    {
        $order = ['incident' => 0, 'change' => 1, 'anomaly' => 2, 'service' => 3, 'check' => 4, 'recovery' => 5];
        $indexed = array_map(null, array_keys($items), $items);
        usort($indexed, fn ($a, $b) => [$a[1]['at'] === null ? 1 : 0, $a[1]['at'] ? CarbonImmutable::parse($a[1]['at'])->getTimestamp() : 0, self::KIND_ORDER[$a[1]['kind']], $order[$a[1]['category']] ?? 9, $a[0]]
            <=> [$b[1]['at'] === null ? 1 : 0, $b[1]['at'] ? CarbonImmutable::parse($b[1]['at'])->getTimestamp() : 0, self::KIND_ORDER[$b[1]['kind']], $order[$b[1]['category']] ?? 9, $b[0]]);

        return array_column($indexed, 1);
    }

    /** @return array<string, int> */
    private static function flatten(array $phases): array
    {
        $out = [];

        foreach (['before', 'during', 'after'] as $phase) {
            $out["{$phase}_ok"] = $phases[$phase]['ok'] ?? 0;
            $out["{$phase}_failed"] = $phases[$phase]['failed'] ?? 0;
        }

        return $out;
    }

    /**
     * Resources with their dependency path from the incident's resource.
     *
     * @return array<string, array<string, mixed>>
     */
    private function resourceRows(Incident $incident, array $resources): array
    {
        $paths = $incident->subject_type && $incident->subject_id
            ? $this->graph->ancestors($incident->subject_type, (int) $incident->subject_id, ScopeResolver::UPSTREAM_DEPTH, (int) $incident->team_id)
            : [];
        $rows = [];

        foreach ($resources as $key => $r) {
            $path = $paths[$key]['path'] ?? null;

            if ($path === null && $r['relation'] === 'service') {
                $server = 'server:'.$r['model']->server_id;
                $path = [...($paths[$server]['path'] ?? [$server]), $key];
            }

            $rows[$key] = ['key' => $key, 'type' => $r['type'], 'id' => (int) $r['model']->getKey(), 'label' => mb_strimwidth($r['label'], 0, 200, '…'), 'relation' => $r['relation'], 'depth' => $r['depth'], 'path' => $path ?? [$key]];
        }

        return $rows;
    }
}
