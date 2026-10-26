<?php

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;

/**
 * Groups related incidents under one root incident.
 *
 * Incidents are only grouped when all of these hold:
 * - the resources are connected by a confirmed dependency (user-defined
 *   or structural: page -> website, service -> server) or are the same
 *   resource;
 * - the root incident is an availability problem that can explain the
 *   other one (see CAUSES: a server that stopped reporting explains a
 *   website that is unreachable, an invalid certificate explains TLS
 *   failures, but high CPU explains nothing by itself);
 * - for incidents that opened before the root, they opened at most
 *   `correlation_window_minutes` before it.
 * Incidents that merely happen at the same time are never grouped.
 * Grouped incidents keep their own timeline, checks and recovery; the
 * reason and evidence are stored in `correlation` and in both timelines.
 */
class CorrelationEngine
{
    /** Root source => failure kinds of other incidents it can explain. */
    public const CAUSES = [
        'agent' => ['availability'],
        'service' => ['availability'],
        'http' => ['availability'],
        'page' => ['availability'],
        'ssl' => ['tls'],
        'dns' => ['dns', 'availability'],
    ];

    public function __construct(
        private DependencyGraph $graph,
        private IncidentManager $incidents,
    ) {}

    /** What kind of failure an incident represents, or null if it cannot be explained by another. */
    public static function failureKind(Incident $incident): ?string
    {
        $context = $incident->context ?? [];
        $error = $context['failure_type'] ?? $context['error'] ?? null;

        return match ($incident->source) {
            'agent', 'service' => 'availability',
            'http', 'page' => match ($error) {
                'tls', 'tls_error' => 'tls',
                'dns', 'dns_error' => 'dns',
                // A page-specific answer (404, wrong content, failed
                // assertion) is produced by a working server.
                'status', 'unexpected_status', 'content', 'content_mismatch', 'header', 'redirect' => null,
                default => 'availability',
            },
            'dns' => 'dns',
            default => null,
        };
    }

    /**
     * Called when an incident opens or reopens: attach it to a root
     * incident upstream, and attach recent downstream incidents to it.
     */
    public function correlate(Incident $incident): void
    {
        if ($incident->subject_type === null || $incident->subject_id === null || ! ResourceCatalog::supports($incident->subject_type)) {
            return;
        }

        if ($incident->parent_id === null) {
            $this->attachUpstream($incident);
        }

        if ($incident->parent_id === null && isset(self::CAUSES[$incident->source])) {
            $this->attachDownstream($incident);
        }
    }

    private function attachUpstream(Incident $incident): void
    {
        $kind = self::failureKind($incident);

        if ($kind === null) {
            return;
        }

        $ancestors = $this->graph->ancestors($incident->subject_type, (int) $incident->subject_id);
        $candidates = $this->activeIncidentsFor($ancestors, $incident->id)
            ->filter(fn (Incident $c) => in_array($kind, self::CAUSES[$c->source] ?? [], true))
            // A grouped incident's own root comes from the same chain.
            ->filter(fn (Incident $c) => $c->parent_id !== $incident->id);

        if ($candidates->isEmpty()) {
            return;
        }

        // Nearest cause first; on equal distance the earliest incident.
        $cause = $candidates
            ->sortBy(fn (Incident $c) => sprintf('%02d-%012d', $ancestors["{$c->subject_type}:{$c->subject_id}"]['depth'], $c->opened_at->getTimestamp()))
            ->first();

        $root = $cause->parent_id !== null ? ($cause->parent()->first() ?? $cause) : $cause;

        if (! $root->isActive() || $root->id === $incident->id) {
            $root = $cause;
        }

        $path = $ancestors["{$cause->subject_type}:{$cause->subject_id}"]['path'];
        $this->attach($incident, $root, $cause, $path, $kind);
    }

    private function attachDownstream(Incident $root): void
    {
        $window = (int) config('serverscope.incidents.correlation_window_minutes', 30);
        $descendants = $this->graph->descendants($root->subject_type, (int) $root->subject_id);
        $explains = self::CAUSES[$root->source];

        $children = $this->activeIncidentsFor($descendants, $root->id)
            ->filter(fn (Incident $c) => $c->parent_id === null)
            ->filter(fn (Incident $c) => $c->opened_at->gte($root->opened_at->copy()->subMinutes($window)))
            ->filter(fn (Incident $c) => in_array(self::failureKind($c), $explains, true));

        foreach ($children as $child) {
            $path = array_reverse($descendants["{$child->subject_type}:{$child->subject_id}"]['path']);
            $this->attach($child, $root, $root, $path, self::failureKind($child));

            // Incidents grouped under the child move to the new root.
            foreach (Incident::query()->where('parent_id', $child->id)->get() as $grandchild) {
                $grandchild->forceFill(['parent_id' => $root->id])->save();
            }
        }
    }

    /**
     * @param  array<string, array{type: string, id: int, depth: int, path: array<int, string>}>  $nodes
     * @return \Illuminate\Support\Collection<int, Incident>
     */
    private function activeIncidentsFor(array $nodes, int $excludeId)
    {
        $byType = [];

        foreach ($nodes as $node) {
            $byType[$node['type']][] = $node['id'];
        }

        return Incident::query()
            ->active()
            ->whereKeyNot($excludeId)
            ->where(function ($query) use ($byType): void {
                foreach ($byType as $type => $ids) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->get();
    }

    /** @param  array<int, string>  $path  resource keys from the child to the cause */
    private function attach(Incident $child, Incident $root, Incident $cause, array $path, string $kind): void
    {
        $names = array_map(fn (string $key) => ResourceCatalog::name(ResourceCatalog::fromKey($key)), $path);
        $sameResource = count($path) === 1;
        $reason = $sameResource
            ? "Zelfde resource: incident #{$cause->id} ({$cause->sourceLabel()}) verklaart deze storing ({$kind})."
            : 'Bevestigde afhankelijkheid '.implode(' → ', $names).": incident #{$cause->id} ({$cause->sourceLabel()}) was al actief.";

        $child->forceFill([
            'parent_id' => $root->id,
            'correlation' => [
                'reason_code' => $sameResource ? 'same_resource' : 'dependency',
                'reason' => $reason,
                'path' => $path,
                'cause_incident_id' => $cause->id,
                'failure_kind' => $kind,
                'cause_opened_at' => $cause->opened_at->toIso8601String(),
                'correlated_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->incidents->event($child, 'correlated', null, "Gegroepeerd onder incident #{$root->id}. {$reason}", ['parent_id' => $root->id, 'path' => $path]);
        $this->incidents->event($root, 'child_added', null, "Incident #{$child->id} ({$child->title}) is hieronder gegroepeerd. {$reason}", ['child_id' => $child->id, 'path' => $path]);

        IncidentImpact::query()->firstOrCreate(
            ['incident_id' => $root->id, 'resource_type' => $child->subject_type, 'resource_id' => $child->subject_id],
            ['role' => 'affected', 'status' => 'failing', 'failure_type' => $kind, 'message' => mb_substr($child->title, 0, 500), 'first_failed_at' => $child->started_at ?? $child->opened_at, 'last_failed_at' => $child->last_occurrence_at],
        );
    }

    /** Undo a grouping by hand (wrong or no longer relevant). */
    public function detach(Incident $child, \App\Models\User $by): void
    {
        $parentId = $child->parent_id;

        if ($parentId === null) {
            return;
        }

        $child->forceFill(['parent_id' => null, 'correlation' => [...($child->correlation ?? []), 'detached_at' => now()->toIso8601String(), 'detached_by' => $by->id]])->save();
        $this->incidents->event($child, 'detached', $by, "Losgekoppeld van incident #{$parentId}.");

        if ($parent = Incident::query()->find($parentId)) {
            $this->incidents->event($parent, 'detached', $by, "Incident #{$child->id} is losgekoppeld.");
        }
    }
}
