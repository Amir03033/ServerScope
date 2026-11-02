<?php

namespace App\Services\Incidents\Evidence;

use App\Models\Incident;
use Carbon\CarbonImmutable;

/**
 * What the collectors read: one incident, its team, the resources in scope
 * (already checked to belong to that team) and the time window. Collectors
 * note here where they hit a row limit and, for checks, how many results
 * succeeded or failed per resource and phase.
 */
final class EvidenceScope
{
    /** @var array<string, array<string, array<int|string, array<string, mixed>>>> type => id => resource */
    public readonly array $byType;

    /** @var array<int, string> collector names that hit a row limit */
    public array $truncated = [];

    /** @var array<string, array<string, array{ok: int, failed: int}>> resource key => phase => counts */
    public array $checks = [];

    /**
     * @param  array<string, array<string, mixed>>  $resources  key => {key, type, model, label, relation, depth}
     * @param  array{onset: CarbonImmutable, opened: CarbonImmutable, end: ?CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable, truncated: bool}  $window
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly array $resources,
        public readonly array $window,
    ) {
        $byType = [];

        foreach ($resources as $resource) {
            $byType[$resource['type']][$resource['model']->getKey()] = $resource;
        }

        $this->byType = $byType;
    }

    public function teamId(): int
    {
        return (int) $this->incident->team_id;
    }

    public function phase(CarbonImmutable $at): string
    {
        return $at->lt($this->window['onset']) ? 'before' : ($this->window['end'] !== null && $at->gt($this->window['end']) ? 'after' : 'during');
    }

    /** The key of the resource with this type and id in scope, or null. */
    public function key(string $type, int|string $id): ?string
    {
        return $this->byType[$type][$id]['key'] ?? null;
    }

    public function truncate(string $collector): void
    {
        $this->truncated[] = $collector;
    }

    public function countCheck(string $resource, CarbonImmutable $at, bool $success): void
    {
        $phase = $this->phase($at);
        $this->checks[$resource][$phase] ??= ['ok' => 0, 'failed' => 0];
        $this->checks[$resource][$phase][$success ? 'ok' : 'failed']++;
    }
}
