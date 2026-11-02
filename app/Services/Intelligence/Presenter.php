<?php

namespace App\Services\Intelligence;

use App\Models\Investigation;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\TimeMachine;
use App\Support\Label;
use App\Support\Time;
use Carbon\CarbonImmutable;

/**
 * Renders a stored investigation in the reader's language. The snapshot
 * holds codes and parameters only; event sentences come from Time Machine
 * (TimeMachine::title), the rest from lang/<locale>/investigations.php.
 * Every string returned here is plain text: views print it escaped.
 */
class Presenter
{
    /** @var array<string, array<string, mixed>> */
    private array $resources = [];

    /** @var array<string, array<string, mixed>> */
    private array $evidence = [];

    public function __construct(public readonly Investigation $investigation)
    {
        $result = $investigation->result ?? [];

        foreach ($result['resources'] ?? [] as $r) {
            $this->resources[$r['key']] = $r;
        }

        foreach ($result['impact']['items'] ?? [] as $i) {
            $this->resources[$i['key']] ??= ['key' => $i['key'], 'type' => $i['type'], 'label' => $i['label'], 'relation' => 'downstream'];
        }

        foreach ($result['evidence'] ?? [] as $e) {
            $this->evidence[$e['key']] = $e;
        }
    }

    public function result(): array
    {
        return $this->investigation->result ?? [];
    }

    /** @return array<string, mixed>|null */
    public function primary(): ?array
    {
        return collect($this->result()['hypotheses'] ?? [])->firstWhere('key', $this->result()['primary'] ?? null);
    }

    /** @return array<string, mixed>|null */
    public function item(string $key): ?array
    {
        return $this->evidence[$key] ?? null;
    }

    /** The stored name of a resource (as it was at the time of the run). */
    public function label(?string $key): string
    {
        if ($key === null) {
            return '—';
        }

        return (string) ($this->resources[$key]['label'] ?? $key);
    }

    public function typeLabel(?string $key): string
    {
        $type = $key !== null ? explode(':', $key)[0] : '';

        return Label::of('resource_type', $type, ResourceCatalog::TYPES);
    }

    /** @param  array<int, string>  $path */
    public function path(array $path): string
    {
        return implode(' → ', array_map(fn ($k) => $this->label($k), $path));
    }

    public function at(?string $iso): ?CarbonImmutable
    {
        return $iso ? CarbonImmutable::parse($iso) : null;
    }

    /** The sentence of one evidence item. */
    public function evidence(array $item): string
    {
        $p = $item['params'] ?? [];

        if ($item['kind'] === 'event') {
            return TimeMachine::title(new EvidenceRecord($this->at($item['at']) ?? CarbonImmutable::now(), $item['category'], $item['code'], $p, $item['resource']));
        }

        $p = match ($item['code']) {
            'metric_shift' => ['metric' => $this->metric($p['metric']), 'before' => $p['before'].$p['unit'], 'baseline' => $p['baseline'].$p['unit'], 'hours' => $p['hours']],
            'metric_high' => ['metric' => $this->metric($p['metric']), 'value' => $p['value'], 'threshold' => $p['threshold']],
            'agent_silent', 'agent_reporting' => ['before' => $p['samples_before'], 'during' => $p['samples_during']],
            'dependent_checks' => ['server' => $this->label($p['server']), 'ok' => $p['ok'], 'failed' => $p['failed']],
            'maintenance' => ['end' => isset($p['ends_at']) ? Time::dateTime($this->at($p['ends_at'])) : __('investigations.evidence.open_ended')],
            'grouped_under' => ['id' => $p['parent_id']],
            default => $p,
        };

        return __('investigations.evidence.'.$item['code'], ['resource' => $this->label($item['resource']), ...$p]);
    }

    public function gap(array $gap): string
    {
        $p = $gap['params'] ?? [];

        return match ($gap['code']) {
            'no_metrics', 'no_raw' => __('time_machine.gaps.'.$gap['code'], ['server' => $this->label($p['resource'] ?? null)]),
            'no_subject', 'no_dependencies', 'no_server' => __('time_machine.gaps.'.$gap['code']),
            'collector_failed' => __('investigations.gaps.collector_failed', ['collector' => __('investigations.collectors.'.($p['collector'] ?? 'unknown'))]),
            'future_samples' => __('investigations.gaps.future_samples', ['server' => $this->label($p['resource'] ?? null), 'count' => $p['count'] ?? 0]),
            default => __('investigations.gaps.'.$gap['code']),
        };
    }

    public function step(array $step): string
    {
        return __('investigations.steps.'.$step['code'], ['resource' => $this->label($step['resource']), 'id' => $step['incident_id'] ?? '—']);
    }

    /** "resource_name: hypothesis" heading. */
    public function hypothesis(array $h): string
    {
        return __('investigations.hypotheses.'.$h['code'].'.title', ['resource' => $this->label($h['resource']), 'id' => $h['params']['incident_id'] ?? '—']);
    }

    public function explanation(array $h): string
    {
        return __('investigations.hypotheses.'.$h['code'].'.text', ['resource' => $this->label($h['resource']), 'id' => $h['params']['incident_id'] ?? '—']);
    }

    /** Evidence items for the explorer, filtered. @return array<int, array<string, mixed>> */
    public function filtered(array $filters, array $supporting = [], array $contradicting = []): array
    {
        return array_values(array_filter($this->result()['evidence'] ?? [], fn ($e) => (empty($filters['resource']) || $e['resource'] === $filters['resource'])
            && (empty($filters['category']) || $e['category'] === $filters['category'] || $e['kind'] === $filters['category'])
            && (empty($filters['phase']) || $e['phase'] === $filters['phase'])));
    }

    /** Role of an evidence item for the primary hypothesis: supporting, contradicting or null. */
    public function role(string $key): ?string
    {
        $primary = $this->primary();

        return match (true) {
            $primary !== null && in_array($key, $primary['supporting'], true) => 'supporting',
            $primary !== null && in_array($key, $primary['contradicting'], true) => 'contradicting',
            default => null,
        };
    }

    private function metric(string $metric): string
    {
        [$base, $dimension] = array_pad(explode(':', $metric, 2), 2, null);

        return __('investigations.metrics.'.$base).($dimension ? " ({$dimension})" : '');
    }
}
