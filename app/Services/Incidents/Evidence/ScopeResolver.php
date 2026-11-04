<?php

namespace App\Services\Incidents\Evidence;

use App\Models\Incident;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The time window and the resources an incident is investigated over,
 * shared by Time Machine and NEXUS Intelligence. Resources come from the
 * same confirmed and structural edges as correlation, within the
 * incident's team only (DependencyGraph checks every edge's team and
 * ResourceCatalog::find() every resource's).
 */
class ScopeResolver
{
    /** Events are included from this long before the onset. */
    public const BEFORE_HOURS = 6;

    /** ...until this long after recovery (or now). */
    public const AFTER_HOURS = 2;

    /** The investigated period is capped for long-running incidents. */
    public const MAX_SPAN_HOURS = 48;

    /** Levels of dependencies (upstream) taken into account. */
    public const UPSTREAM_DEPTH = 3;

    public function __construct(private DependencyGraph $graph) {}

    /** @return array{onset: CarbonImmutable, opened: CarbonImmutable, end: ?CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable, truncated: bool} */
    public function window(Incident $incident, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $onset = CarbonImmutable::instance($incident->started_at ?? $incident->opened_at);
        $end = CarbonImmutable::instance($incident->resolved_at ?? $now);
        $cappedEnd = $end->min($onset->addHours(self::MAX_SPAN_HOURS));

        return [
            'onset' => $onset,
            'opened' => CarbonImmutable::instance($incident->opened_at),
            'end' => $incident->resolved_at ? $end : null,
            'from' => $onset->subHours(self::BEFORE_HOURS),
            'to' => $cappedEnd->addHours(self::AFTER_HOURS)->min($now),
            'truncated' => $cappedEnd->lt($end),
        ];
    }

    /**
     * The incident's resource, what it depends on (up to UPSTREAM_DEPTH
     * levels, same team), and the services running on every server among
     * them.
     *
     * @return array<string, array{key: string, type: string, model: Model, label: string, relation: string, depth: int}>
     */
    public function resources(Incident $incident): array
    {
        [$type, $id] = [$incident->subject_type, $incident->subject_id];

        // A scheduled job is investigated through the server it runs on.
        if ($type === 'heartbeat') {
            $serverId = \App\Models\Heartbeat::query()->withoutGlobalScopes()->whereKey($id)->value('server_id');
            [$type, $id] = $serverId ? ['server', $serverId] : [null, null];
        }

        if ($type === null || ! ResourceCatalog::supports($type)) {
            return [];
        }

        $teamId = (int) $incident->team_id;
        $resources = [];
        $ancestors = $this->graph->ancestors($type, (int) $id, self::UPSTREAM_DEPTH, $teamId);

        foreach ($ancestors as $key => $node) {
            $model = ResourceCatalog::find($node['type'], $node['id'], $teamId);

            if ($model !== null) {
                $resources[$key] = ['key' => $key, 'type' => $node['type'], 'model' => $model, 'label' => ResourceCatalog::name($model), 'relation' => $node['depth'] === 0 ? 'subject' : 'upstream', 'depth' => $node['depth']];
            }
        }

        foreach ($resources as $resource) {
            if ($resource['model'] instanceof Server) {
                foreach (ServiceCheck::query()->withoutGlobalScopes()->where('server_id', $resource['model']->id)->get() as $service) {
                    $key = 'service:'.$service->id;
                    $resources[$key] ??= ['key' => $key, 'type' => 'service', 'model' => $service, 'label' => ResourceCatalog::name($service), 'relation' => 'service', 'depth' => $resource['depth'] + 1];
                }
            }
        }

        return $resources;
    }

    /**
     * Limits of the scope itself (codes; MetricEvidence adds metric gaps).
     *
     * @param  array<string, array<string, mixed>>  $resources
     * @return array<int, array{code: string, params: array<string, scalar>}>
     */
    public static function gaps(array $resources): array
    {
        return match (count($resources)) {
            0 => [['code' => 'no_subject', 'params' => []]],
            1 => [['code' => 'no_dependencies', 'params' => []]],
            default => [],
        };
    }
}
