<?php

namespace App\Services\Dependencies;

use App\Models\DnsSnapshot;
use App\Models\Domain;
use App\Models\ResourceDependency;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Directed dependency graph between monitored resources.
 *
 * Edges are either confirmed (user-defined ResourceDependency rows, or
 * structural: a page belongs to its website, a service runs on its
 * server) or inferred (e.g. a domain resolves to a server's IP address).
 * Only confirmed edges are used to group incidents; inferred ones are
 * shown as suggestions that a user can confirm.
 */
class DependencyGraph
{
    public const MAX_DEPTH = 6;

    /**
     * Direct confirmed dependencies of a resource.
     *
     * @return array<int, array{type: string, id: int, label: ?string, origin: string, dependency_id: ?int}>
     */
    public function upstream(string $type, int $id): array
    {
        $edges = [];

        foreach ($this->structuralUpstream($type, $id) as $edge) {
            $edges[] = $edge;
        }

        $rows = ResourceDependency::query()
            ->where('dependent_type', $type)
            ->where('dependent_id', $id)
            ->get(['id', 'dependency_type', 'dependency_id', 'label']);

        foreach ($rows as $row) {
            $edges[] = ['type' => $row->dependency_type, 'id' => (int) $row->dependency_id, 'label' => $row->label, 'origin' => 'confirmed', 'dependency_id' => $row->id];
        }

        return $edges;
    }

    /**
     * Direct confirmed dependents of a resource.
     *
     * @return array<int, array{type: string, id: int, label: ?string, origin: string, dependency_id: ?int}>
     */
    public function downstream(string $type, int $id): array
    {
        $edges = [];

        if ($type === 'domain' && DB::getSchemaBuilder()->hasTable('monitored_pages')) {
            foreach (DB::table('monitored_pages')->where('domain_id', $id)->where('approval_status', 'approved')->pluck('id') as $pageId) {
                $edges[] = ['type' => 'page', 'id' => (int) $pageId, 'label' => 'pagina van', 'origin' => 'structural', 'dependency_id' => null];
            }
        }

        if ($type === 'server' && DB::getSchemaBuilder()->hasTable('service_checks')) {
            foreach (DB::table('service_checks')->where('server_id', $id)->pluck('id') as $serviceId) {
                $edges[] = ['type' => 'service', 'id' => (int) $serviceId, 'label' => 'draait op', 'origin' => 'structural', 'dependency_id' => null];
            }
        }

        $rows = ResourceDependency::query()
            ->where('dependency_type', $type)
            ->where('dependency_id', $id)
            ->get(['id', 'dependent_type', 'dependent_id', 'label']);

        foreach ($rows as $row) {
            $edges[] = ['type' => $row->dependent_type, 'id' => (int) $row->dependent_id, 'label' => $row->label, 'origin' => 'confirmed', 'dependency_id' => $row->id];
        }

        return $edges;
    }

    /**
     * The resource itself (depth 0) and everything it depends on,
     * breadth first, each with the path from the resource.
     *
     * @return array<string, array{type: string, id: int, depth: int, path: array<int, string>}>
     */
    public function ancestors(string $type, int $id, int $maxDepth = self::MAX_DEPTH): array
    {
        return $this->walk($type, $id, $maxDepth, fn (string $t, int $i) => $this->upstream($t, $i));
    }

    /**
     * The resource itself (depth 0) and everything that depends on it.
     *
     * @return array<string, array{type: string, id: int, depth: int, path: array<int, string>}>
     */
    public function descendants(string $type, int $id, int $maxDepth = self::MAX_DEPTH): array
    {
        return $this->walk($type, $id, $maxDepth, fn (string $t, int $i) => $this->downstream($t, $i));
    }

    /** Whether adding "dependent depends on dependency" would create a cycle. */
    public function wouldCreateCycle(string $dependentType, int $dependentId, string $dependencyType, int $dependencyId): bool
    {
        if ($dependentType === $dependencyType && $dependentId === $dependencyId) {
            return true;
        }

        return isset($this->ancestors($dependencyType, $dependencyId, 20)["{$dependentType}:{$dependentId}"]);
    }

    /**
     * Relations that are likely but not confirmed: a domain whose last
     * known A/AAAA records contain a server's IP address. Already
     * confirmed relations are left out.
     *
     * @return array<int, array{type: string, id: int, direction: string, reason: string}>
     */
    public function inferred(Model $resource): array
    {
        $suggestions = [];

        if ($resource instanceof Domain) {
            $ips = $this->domainAddresses($resource);

            if ($ips !== []) {
                foreach (Server::query()->whereIn('ip_address', $ips)->get(['id', 'name', 'ip_address']) as $server) {
                    if (! $this->hasConfirmedEdge('domain', $resource->id, 'server', $server->id)) {
                        $suggestions[] = ['type' => 'server', 'id' => $server->id, 'direction' => 'upstream', 'reason' => "{$resource->displayName()} verwijst in DNS naar {$server->ip_address}, het IP-adres van {$server->name}."];
                    }
                }
            }
        }

        if ($resource instanceof Server && $resource->ip_address) {
            $domainIds = DnsSnapshot::query()
                ->whereIn('record_type', ['A', 'AAAA'])
                ->where('records', 'like', '%"'.$resource->ip_address.'"%')
                ->distinct()
                ->pluck('domain_id');

            foreach (Domain::query()->whereIn('id', $domainIds)->get() as $domain) {
                if (in_array($resource->ip_address, $this->domainAddresses($domain), true)
                    && ! $this->hasConfirmedEdge('domain', $domain->id, 'server', $resource->id)) {
                    $suggestions[] = ['type' => 'domain', 'id' => $domain->id, 'direction' => 'downstream', 'reason' => "{$domain->displayName()} verwijst in DNS naar {$resource->ip_address}, het IP-adres van deze server."];
                }
            }
        }

        return $suggestions;
    }

    /** @return array<int, string> */
    private function domainAddresses(Domain $domain): array
    {
        $ips = [];

        foreach (['A', 'AAAA'] as $type) {
            $snapshot = $domain->dnsSnapshots()->where('record_type', $type)->latest('last_seen_at')->latest('id')->first();

            foreach ((array) ($snapshot?->records ?? []) as $record) {
                $value = is_array($record) ? ($record['value'] ?? null) : $record;

                if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP)) {
                    $ips[] = $value;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function hasConfirmedEdge(string $dependentType, int $dependentId, string $dependencyType, int $dependencyId): bool
    {
        return ResourceDependency::query()
            ->where('dependent_type', $dependentType)->where('dependent_id', $dependentId)
            ->where('dependency_type', $dependencyType)->where('dependency_id', $dependencyId)
            ->exists();
    }

    /** @return array<int, array{type: string, id: int, label: ?string, origin: string, dependency_id: ?int}> */
    private function structuralUpstream(string $type, int $id): array
    {
        if ($type === 'page' && DB::getSchemaBuilder()->hasTable('monitored_pages')) {
            $domainId = DB::table('monitored_pages')->where('id', $id)->value('domain_id');

            return $domainId ? [['type' => 'domain', 'id' => (int) $domainId, 'label' => 'pagina van', 'origin' => 'structural', 'dependency_id' => null]] : [];
        }

        if ($type === 'service' && DB::getSchemaBuilder()->hasTable('service_checks')) {
            $serverId = DB::table('service_checks')->where('id', $id)->value('server_id');

            return $serverId ? [['type' => 'server', 'id' => (int) $serverId, 'label' => 'draait op', 'origin' => 'structural', 'dependency_id' => null]] : [];
        }

        return [];
    }

    /**
     * @param  callable(string, int): array  $next
     * @return array<string, array{type: string, id: int, depth: int, path: array<int, string>}>
     */
    private function walk(string $type, int $id, int $maxDepth, callable $next): array
    {
        $start = "{$type}:{$id}";
        $seen = [$start => ['type' => $type, 'id' => $id, 'depth' => 0, 'path' => [$start]]];
        $queue = [$start];

        while ($queue !== []) {
            $key = array_shift($queue);
            $node = $seen[$key];

            if ($node['depth'] >= $maxDepth) {
                continue;
            }

            foreach ($next($node['type'], $node['id']) as $edge) {
                $edgeKey = "{$edge['type']}:{$edge['id']}";

                if (isset($seen[$edgeKey])) {
                    continue;
                }

                $seen[$edgeKey] = ['type' => $edge['type'], 'id' => $edge['id'], 'depth' => $node['depth'] + 1, 'path' => [...$node['path'], $edgeKey]];
                $queue[] = $edgeKey;
            }
        }

        return $seen;
    }
}
