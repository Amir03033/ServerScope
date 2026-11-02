<?php

namespace App\Services\Nexus;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Support\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The infrastructure map of one team (NEXUS): servers, services, domains
 * and their pages as nodes, structural and confirmed dependencies as
 * edges, with monitoring health, active incidents and blast radius.
 *
 * - Every query filters on the given team explicitly, also where a
 *   global scope would (or, without a tenant, would not) do so. Services
 *   and incident impacts have no team_id and are reached through their
 *   team-filtered parents.
 * - The number of queries is fixed (at most nine), whatever the size of
 *   the team; the graph walk for the blast radius runs in memory.
 * - Only approved pages appear. They are aggregated per domain into one
 *   "page_group" node; pages that take part in a confirmed dependency
 *   get a node of their own (and are then not counted in the group).
 * - Inferred relations (DNS/IP suggestions) are left out: only edges
 *   that DependencyGraph also uses for correlation appear.
 * - No connection details (hostname, IP address), agent data or
 *   credentials are included. Labels are plain text.
 * - The payload does not depend on the interface language (like the
 *   API): it holds names and codes only. Structural edges carry a
 *   relation code (runs_on, page_of) and page groups no label; the page
 *   translates them. One cache entry and ETag serve every language.
 */
class TopologyBuilder
{
    public const VERSION = 1;

    /** Seconds a team's topology is cached; the maximum staleness of health data. */
    public const CACHE_TTL_SECONDS = 30;

    /**
     * Upper bounds per kind of row. A kind that hit its bound is listed in
     * meta.truncated; the counts in meta always describe what is on the
     * map (e.g. active_incidents is then the number of incidents loaded).
     */
    public const LIMITS = [
        'server' => 1000,
        'domain' => 1000,
        'service' => 2000,
        'page' => 500,
        'dependency' => 5000,
        'incident' => 500,
        'impact' => 5000,
    ];

    public const HEALTH_STATES = ['ok', 'warning', 'critical', 'unknown', 'maintenance', 'paused'];

    private const SERVER_HEALTH = ['online' => 'ok', 'offline' => 'critical', 'pending' => 'unknown', 'maintenance' => 'maintenance'];

    private const DOMAIN_HEALTH = ['healthy' => 'ok', 'warning' => 'warning', 'critical' => 'critical', 'unknown' => 'unknown', 'paused' => 'paused', 'maintenance' => 'maintenance'];

    private const SERVICE_HEALTH = ['running' => 'ok', 'starting' => 'warning', 'stopped' => 'critical', 'failed' => 'critical', 'not_found' => 'critical', 'stale' => 'unknown', 'unknown' => 'unknown', 'paused' => 'paused'];

    private const PAGE_HEALTH = ['up' => 'ok', 'degraded' => 'warning', 'down' => 'critical', 'unknown' => 'unknown', 'paused' => 'paused'];

    private const HEALTH_RANK = ['paused' => 0, 'maintenance' => 0, 'ok' => 1, 'unknown' => 2, 'warning' => 3, 'critical' => 4];

    private const TYPE_ORDER = ['server' => 0, 'service' => 1, 'domain' => 2, 'page_group' => 3, 'page' => 4];

    public static function cacheKey(int $teamId): string
    {
        return 'nexus:topology:'.$teamId;
    }

    /** The team's topology, cached for CACHE_TTL_SECONDS. */
    public function forTeam(int $teamId): array
    {
        return Cache::remember(self::cacheKey($teamId), self::CACHE_TTL_SECONDS, fn () => $this->build($teamId));
    }

    public static function forget(?int $teamId): void
    {
        if ($teamId !== null) {
            Cache::forget(self::cacheKey($teamId));
        }
    }

    /**
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function build(int $teamId): array
    {
        $now = now();
        $truncated = [];
        $nodes = [];
        $edges = [];

        $serverIds = fn () => Server::query()->where('team_id', $teamId)->select('id');
        $domainIds = fn () => Domain::query()->where('team_id', $teamId)->select('id');

        // 1-2. Servers (not deleted) with their latest metrics.
        $servers = $this->bounded(
            Server::query()->where('team_id', $teamId)->with('latestMetricSample')->orderBy('id'),
            'server', $truncated,
            ['id', 'team_id', 'name', 'environment', 'status', 'last_seen_at', 'maintenance_starts_at', 'maintenance_ends_at'],
        );

        foreach ($servers as $server) {
            $status = $server->displayStatus($now);
            $sample = $server->latestMetricSample;

            $key = ResourceCatalog::key($server);
            $nodes[$key] = $this->node($key, 'server', $server->id, $server->name, $status, self::SERVER_HEALTH[$status] ?? 'unknown', route('servers.show', $server->id), null, [
                'environment' => $server->environment,
                'last_seen_at' => $server->last_seen_at?->toIso8601String(),
                'metrics' => $sample ? [
                    'collected_at' => $sample->collected_at?->toIso8601String(),
                    'cpu_percent' => $sample->cpu_percent,
                    'memory_percent' => $sample->memory_total_bytes && $sample->memory_used_bytes !== null ? round($sample->memory_used_bytes / $sample->memory_total_bytes * 100, 1) : null,
                ] : null,
            ]);
        }

        // 3. Services of those servers (service_checks has no team_id).
        $services = $this->bounded(
            ServiceCheck::query()->whereIn('server_id', $serverIds())->orderBy('id'),
            'service', $truncated,
            ['id', 'server_id', 'name', 'display_name', 'kind', 'is_enabled', 'status'],
        );

        foreach ($services as $service) {
            $serverKey = "server:{$service->server_id}";

            if (! isset($nodes[$serverKey])) {
                continue;
            }

            $status = $service->is_enabled ? $service->status : 'paused';
            $nodes["service:{$service->id}"] = $this->node("service:{$service->id}", 'service', $service->id, $service->display_name ?: $service->name, $status, self::SERVICE_HEALTH[$status] ?? 'unknown', route('servers.show', $service->server_id), $serverKey, [
                'kind' => $service->kind,
            ]);
            $this->edge($edges, "service:{$service->id}", $serverKey, 'structural', null, relation: 'runs_on');
        }

        // 4. Domains (not deleted).
        $domains = $this->bounded(
            Domain::query()->where('team_id', $teamId)->orderBy('id'),
            'domain', $truncated,
            ['id', 'team_id', 'name', 'is_active', 'status', 'maintenance_starts_at', 'maintenance_ends_at', 'http_status', 'ssl_status', 'dns_status', 'expiry_status', 'http_last_response_ms', 'http_last_checked_at'],
        );

        foreach ($domains as $domain) {
            $status = $domain->displayStatus();
            $key = ResourceCatalog::key($domain);
            $nodes[$key] = $this->node($key, 'domain', $domain->id, $domain->displayName(), $status, self::DOMAIN_HEALTH[$status] ?? 'unknown', route('domains.show', $domain->id), null, [
                'http_status' => $domain->http_status,
                'ssl_status' => $domain->ssl_status,
                'dns_status' => $domain->dns_status,
                'expiry_status' => $domain->expiry_status,
                // Last measured response time (3D view: flow speed); null when not measured.
                'response_ms' => $domain->http_last_response_ms !== null ? (int) round($domain->http_last_response_ms) : null,
            ]);
        }

        // 5. Confirmed dependencies of the team.
        $dependencies = $this->bounded(
            ResourceDependency::query()->where('team_id', $teamId)->orderBy('id'),
            'dependency', $truncated,
            ['id', 'dependent_type', 'dependent_id', 'dependency_type', 'dependency_id', 'label'],
        );

        // 6. Approved pages that take part in a dependency get their own
        // node; dependencies on pending or rejected pages are not shown.
        $pageIds = $dependencies->flatMap(fn (ResourceDependency $d) => [
            $d->dependent_type === 'page' ? (int) $d->dependent_id : null,
            $d->dependency_type === 'page' ? (int) $d->dependency_id : null,
        ])->filter()->unique()->sort()->values();

        if ($pageIds->count() > static::LIMITS['page']) {
            $truncated[] = 'page';
            $pageIds = $pageIds->take(static::LIMITS['page']);
        }

        $individualPages = [];

        if ($pageIds->isNotEmpty()) {
            $pages = MonitoredPage::query()
                ->where('team_id', $teamId)
                ->whereIn('domain_id', $domainIds())
                ->where('approval_status', 'approved')
                ->whereIn('id', $pageIds->all())
                ->orderBy('id')
                ->get(['id', 'domain_id', 'url', 'title', 'approval_status', 'is_active', 'status']);

            foreach ($pages as $page) {
                $domainKey = "domain:{$page->domain_id}";

                if (! isset($nodes[$domainKey])) {
                    continue;
                }

                $page->setRelation('domain', $domains->firstWhere('id', $page->domain_id));
                $status = $page->displayStatus();
                $nodes["page:{$page->id}"] = $this->node("page:{$page->id}", 'page', $page->id, $page->title ?: $page->displayName(), $status, self::PAGE_HEALTH[$status] ?? 'unknown', route('pages.show', $page->id), $domainKey, []);
                $this->edge($edges, "page:{$page->id}", $domainKey, 'structural', null, relation: 'page_of');
                $individualPages[] = $page->id;
            }
        }

        // 7. The other approved pages, aggregated per domain.
        $pageRows = MonitoredPage::query()
            ->where('team_id', $teamId)
            ->whereIn('domain_id', $domainIds())
            ->where('approval_status', 'approved')
            ->when($individualPages !== [], fn (Builder $q) => $q->whereNotIn('id', $individualPages))
            ->selectRaw('domain_id, is_active, status, COUNT(*) as n')
            ->groupBy('domain_id', 'is_active', 'status')
            ->toBase()
            ->get();

        $groups = [];

        foreach ($pageRows as $row) {
            $state = $row->is_active ? (string) $row->status : 'paused';
            $groups[(int) $row->domain_id][$state] = ($groups[(int) $row->domain_id][$state] ?? 0) + (int) $row->n;
        }

        ksort($groups);

        foreach ($groups as $domainId => $counts) {
            $domainKey = "domain:{$domainId}";

            if (! isset($nodes[$domainKey])) {
                continue;
            }

            $counts = ['total' => array_sum($counts), ...array_fill_keys(['up', 'degraded', 'down', 'unknown', 'paused'], 0), ...$counts];
            $status = $this->worstPageStatus($counts);
            $key = "page_group:{$domainId}";
            $nodes[$key] = $this->node($key, 'page_group', null, null, $status, self::PAGE_HEALTH[$status] ?? 'unknown', route('websites.pages', $domainId), $domainKey, [
                'pages' => $counts,
            ]);
            $this->edge($edges, $key, $domainKey, 'structural', null, relation: 'page_of');
        }

        // Confirmed edges whose ends are both on the map.
        foreach ($dependencies as $dependency) {
            $source = "{$dependency->dependent_type}:{$dependency->dependent_id}";
            $target = "{$dependency->dependency_type}:{$dependency->dependency_id}";

            if (isset($nodes[$source], $nodes[$target]) && $source !== $target) {
                $this->edge($edges, $source, $target, 'confirmed', $dependency->label, $dependency->id);
            }
        }

        // 8-9. Active incidents and the resources they affect.
        $incidentCount = $this->applyIncidents($nodes, $teamId, $truncated);

        $this->applyBlastRadius($nodes, $edges);

        // By type, then by the number in the key (the domain id for page groups).
        $order = fn (array $n) => [self::TYPE_ORDER[$n['type']], (int) substr((string) strrchr($n['key'], ':'), 1)];
        uasort($nodes, fn (array $a, array $b) => $order($a) <=> $order($b));

        $countByType = array_fill_keys(array_keys(self::TYPE_ORDER), 0);

        foreach ($nodes as $node) {
            $countByType[$node['type']]++;
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'meta' => [
                'version' => self::VERSION,
                'generated_at' => $now->toIso8601String(),
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
                'max_depth' => DependencyGraph::MAX_DEPTH,
                'counts' => [
                    'nodes' => count($nodes),
                    'edges' => count($edges),
                    'by_type' => $countByType,
                    ...$incidentCount,
                ],
                'truncated' => array_values(array_unique($truncated)),
            ],
        ];
    }

    /**
     * Attach active incidents to the nodes of their subject and of every
     * resource they (still) affect, and raise node health accordingly.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<int, string>  $truncated
     * @return array{active_incidents: int, unplaced_incidents: int}
     */
    private function applyIncidents(array &$nodes, int $teamId, array &$truncated): array
    {
        // Qualified columns: the queries join monitored_pages, which also has a status.
        $active = fn () => Incident::query()->where('incidents.team_id', $teamId)->whereIn('incidents.status', Incident::ACTIVE_STATUSES);

        // Pages are looked up (approved, within the team) to find their
        // domain, for pages that are part of a page group instead of a node.
        $incidents = $this->bounded(
            $active()
                ->leftJoin('monitored_pages as nexus_pages', fn ($join) => $join
                    ->on('nexus_pages.id', '=', 'incidents.subject_id')
                    ->where('incidents.subject_type', 'page')
                    ->where('nexus_pages.team_id', $teamId)
                    ->where('nexus_pages.approval_status', 'approved'))
                ->orderByDesc('incidents.opened_at')->orderByDesc('incidents.id'),
            'incident', $truncated,
            ['incidents.id', 'incidents.subject_type', 'incidents.subject_id', 'incidents.severity', 'incidents.status', 'incidents.title', 'incidents.parent_id', 'incidents.opened_at', 'nexus_pages.domain_id as page_domain_id'],
        );

        $touched = [];
        $loaded = $incidents->pluck('id')->flip();

        foreach ($incidents as $incident) {
            if ($key = $this->resourceNode($nodes, $incident->subject_type, $incident->subject_id, $incident->page_domain_id)) {
                $touched[$incident->id][$key] = true;
            }
        }

        if ($incidents->isNotEmpty()) {
            // Only impacts of the loaded incidents, which are the team's (the
            // subquery repeats the team filter rather than trusting the ids).
            $impacts = $this->bounded(
                IncidentImpact::query()
                    ->whereIn('incident_impacts.incident_id', $active()->select('incidents.id'))
                    ->whereIn('incident_impacts.incident_id', $loaded->keys()->all())
                    ->where('incident_impacts.status', 'failing')
                    ->leftJoin('monitored_pages as nexus_pages', fn ($join) => $join
                        ->on('nexus_pages.id', '=', 'incident_impacts.resource_id')
                        ->where('incident_impacts.resource_type', 'page')
                        ->where('nexus_pages.team_id', $teamId)
                        ->where('nexus_pages.approval_status', 'approved'))
                    ->orderBy('incident_impacts.id'),
                'impact', $truncated,
                ['incident_impacts.incident_id', 'incident_impacts.resource_type', 'incident_impacts.resource_id', 'nexus_pages.domain_id as page_domain_id'],
            );

            foreach ($impacts as $impact) {
                if (($key = $this->resourceNode($nodes, $impact->resource_type, $impact->resource_id, $impact->page_domain_id))) {
                    $touched[$impact->incident_id][$key] = true;
                }
            }
        }

        $unplaced = 0;

        // Most recent first, so the first incident of the highest severity is the primary one.
        foreach ($incidents as $incident) {
            if (empty($touched[$incident->id])) {
                $unplaced++;

                continue;
            }

            foreach (array_keys($touched[$incident->id]) as $key) {
                $summary = &$nodes[$key]['incidents'];
                $summary['active']++;

                if ($summary['primary'] === null || Severity::rank($incident->severity) > Severity::rank($summary['max_severity'])) {
                    $summary['max_severity'] = $incident->severity;
                    $summary['primary'] = [
                        'id' => $incident->id,
                        'title' => $incident->title,
                        'severity' => $incident->severity,
                        'status' => $incident->status,
                        'opened_at' => $incident->opened_at?->toIso8601String(),
                        'grouped' => $incident->parent_id !== null,
                        'url' => route('incidents.show', $incident->id),
                        // NEXUS Intelligence: the incident's latest investigation (or its empty state).
                        'investigation_url' => route('incidents.investigation', $incident->id),
                    ];
                }

                unset($summary);
            }
        }

        foreach ($nodes as &$node) {
            $node['health'] = $this->withIncident($node['health'], $node['incidents']['max_severity']);
        }

        unset($node);

        return ['active_incidents' => $incidents->count(), 'unplaced_incidents' => $unplaced];
    }

    /**
     * Number of nodes that (transitively, up to DependencyGraph::MAX_DEPTH)
     * depend on each node: what may be affected when it fails.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     */
    private function applyBlastRadius(array &$nodes, array $edges): void
    {
        $dependents = [];

        foreach ($edges as $edge) {
            $dependents[$edge['target']][] = $edge['source'];
        }

        foreach (array_keys($nodes) as $start) {
            $seen = [$start => 0];
            $queue = [$start];

            while ($queue !== []) {
                $key = array_shift($queue);

                if ($seen[$key] >= DependencyGraph::MAX_DEPTH) {
                    continue;
                }

                foreach ($dependents[$key] ?? [] as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = $seen[$key] + 1;
                        $queue[] = $next;
                    }
                }
            }

            $nodes[$start]['blast_radius'] = count($seen) - 1;
        }
    }

    /**
     * The node that represents a resource: its own node, or for a page
     * without one, the page group of its domain.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function resourceNode(array $nodes, ?string $type, int|string|null $id, int|string|null $pageDomainId): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        if (isset($nodes["{$type}:{$id}"])) {
            return "{$type}:{$id}";
        }

        if ($type === 'page' && $pageDomainId !== null && isset($nodes["page_group:{$pageDomainId}"])) {
            return "page_group:{$pageDomainId}";
        }

        return null;
    }

    /** Monitoring health raised by the most severe active incident (not during maintenance or pause). */
    private function withIncident(string $health, ?string $severity): string
    {
        if ($severity === null || in_array($health, ['maintenance', 'paused'], true)) {
            return $health;
        }

        $fromIncident = match (true) {
            Severity::atLeast($severity, Severity::HIGH) => 'critical',
            Severity::atLeast($severity, Severity::MEDIUM) => 'warning',
            default => $health,
        };

        return self::HEALTH_RANK[$fromIncident] > self::HEALTH_RANK[$health] ? $fromIncident : $health;
    }

    /** @param  array<string, int>  $counts */
    private function worstPageStatus(array $counts): string
    {
        foreach (['down', 'degraded', 'unknown', 'up'] as $status) {
            if (($counts[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return 'paused';
    }

    /** @return array<string, mixed> */
    private function node(string $key, string $type, ?int $id, ?string $label, string $status, string $health, ?string $url, ?string $parent, array $details): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'health' => $health,
            'url' => $url,
            'parent' => $parent,
            'details' => $details,
            'incidents' => ['active' => 0, 'max_severity' => null, 'primary' => null],
            'blast_radius' => 0,
        ];
    }

    /** @param  array<string, array<string, mixed>>  $edges */
    private function edge(array &$edges, string $source, string $target, string $origin, ?string $label, ?int $dependencyId = null, ?string $relation = null): void
    {
        $key = "{$source}->{$target}";

        // A confirmed dependency replaces a structural edge between the same nodes.
        if (isset($edges[$key]) && $origin !== 'confirmed') {
            return;
        }

        $edges[$key] = [
            'key' => $key,
            'source' => $source,
            'target' => $target,
            'origin' => $origin,
            'relation' => $relation,
            'label' => $label,
            'dependency_id' => $dependencyId,
        ];
    }

    /**
     * Run a query with one row more than the limit, to detect truncation.
     *
     * @param  array<int, string>  $truncated
     * @param  array<int, string>  $columns
     */
    private function bounded(Builder $query, string $kind, array &$truncated, array $columns): Collection
    {
        $limit = static::LIMITS[$kind];
        $rows = $query->limit($limit + 1)->get($columns);

        if ($rows->count() > $limit) {
            $truncated[] = $kind;
            $rows = $rows->take($limit);
        }

        return $rows;
    }
}
