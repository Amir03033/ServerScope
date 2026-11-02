<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * Last check before a result is stored: every resource ("type:id") and
 * every source row it refers to must belong to the investigation's team.
 * Collectors already filter by team; this guard makes a mistake there (or
 * an inconsistent row) fail the investigation instead of storing another
 * team's identifiers. One query per referenced type.
 */
class TeamIntegrityGuard
{
    /** Rows with their own team_id. */
    private const OWN = [
        'incident' => 'incidents',
        'deployment' => 'deployments',
        'audit_log' => 'audit_logs',
        'prediction' => 'predictions',
        'agent_update_request' => 'agent_update_requests',
        'server' => 'servers',
        'domain' => 'domains',
        'page' => 'monitored_pages',
    ];

    /** Child rows: table => [foreign key, parent type]. */
    private const CHILD = [
        'incident_event' => ['incident_events', 'incident_id', 'incident'],
        'domain_check' => ['domain_checks', 'domain_id', 'domain'],
        'page_check' => ['page_checks', 'monitored_page_id', 'page'],
        'dns_change' => ['dns_changes', 'domain_id', 'domain'],
        'service' => ['service_checks', 'server_id', 'server'],
        'service_check_event' => ['service_check_events', 'service_check_id', 'service'],
    ];

    /** @throws IntegrityViolation */
    public function assert(int $teamId, array $result): void
    {
        $refs = [];

        foreach ($this->references($result) as [$type, $id]) {
            $refs[$type][$id] = true;
        }

        foreach ($refs as $type => $ids) {
            $ids = array_keys($ids);

            if (count($this->owned($teamId, $type, $ids)) !== count($ids)) {
                throw new IntegrityViolation("Reference of type {$type} outside the team.");
            }
        }
    }

    /** @return array<int, int> ids of $type that belong to the team */
    private function owned(int $teamId, string $type, array $ids): array
    {
        if (isset(self::OWN[$type])) {
            return DB::table(self::OWN[$type])->where('team_id', $teamId)->whereIn('id', $ids)->pluck('id')->all();
        }

        if (isset(self::CHILD[$type])) {
            [$table, $fk, $parent] = self::CHILD[$type];
            $rows = DB::table($table)->whereIn('id', $ids)->get(['id', $fk]);
            $parents = $rows->pluck($fk)->unique()->map(fn ($v) => (int) $v)->values()->all();
            $owned = $parents === [] ? [] : $this->owned($teamId, $parent, $parents);

            return $rows->filter(fn ($r) => in_array((int) $r->{$fk}, $owned, true))->pluck('id')->all();
        }

        throw new IntegrityViolation("Unknown reference type {$type}.");
    }

    /** @return iterable<int, array{0: string, 1: int}> */
    private function references(array $result): iterable
    {
        $key = function (?string $key): ?array {
            return $key !== null && preg_match('/^([a-z_]+):(\d+)$/', $key, $m) ? [$m[1], (int) $m[2]] : null;
        };
        $keys = [];

        $keys[] = ['incident', (int) $result['incident']['id']];
        $keys[] = $key($result['incident']['subject'] ?? null);

        foreach ($result['resources'] as $r) {
            $keys[] = $key($r['key']);
            array_push($keys, ...array_map($key, $r['path']));
        }

        foreach ($result['evidence'] as $item) {
            $keys[] = $key($item['resource']);
            $keys[] = $item['source'] ? [$item['source']['type'], (int) $item['source']['id']] : null;

            if (isset($item['params']['server'])) {
                $keys[] = $key($item['params']['server']);
            }
        }

        foreach ([...$result['hypotheses'], ...$result['rejected']] as $h) {
            $keys[] = $key($h['resource']);
            array_push($keys, ...array_map($key, $h['path']));

            if (isset($h['params']['incident_id'])) {
                $keys[] = ['incident', (int) $h['params']['incident_id']];
            }
        }

        $keys[] = $key($result['impact']['cause'] ?? null);
        $keys[] = $key($result['impact']['via'] ?? null);

        foreach ($result['impact']['items'] as $i) {
            $keys[] = $key($i['key']);
            array_push($keys, ...array_map($key, $i['path']));

            foreach ($i['observed']['incidents'] as $id) {
                $keys[] = ['incident', (int) $id];
            }
        }

        foreach ($result['impact']['inferred'] as $i) {
            $keys[] = [$i['type'], (int) $i['id']];
        }

        return array_filter($keys);
    }
}
