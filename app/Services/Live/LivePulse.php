<?php

namespace App\Services\Live;

use App\Models\Incident;
use App\Services\Nexus\TopologyBuilder;

/**
 * The pulse of a team in one small answer, for the live logo and tab
 * icon: the overall state, the active incidents and a heart rate from
 * the average CPU of the servers (same scale as the dashboard heartbeat).
 * Built on the cached NEXUS topology, so polling it is cheap.
 */
class LivePulse
{
    public const MIN_BPM = 50;

    public const MAX_BPM = 140;

    public function __construct(private TopologyBuilder $topology) {}

    /** @return array{state: string, incidents: int, critical: int, offline: int, bpm: int} */
    public function forTeam(int $teamId): array
    {
        $nodes = $this->topology->forTeam($teamId)['nodes'];
        $servers = array_values(array_filter($nodes, fn (array $n) => $n['type'] === 'server'));

        $incidents = Incident::query()->withoutGlobalScopes()->where('team_id', $teamId)->active();
        $active = (clone $incidents)->count();
        $critical = (clone $incidents)->where('severity', 'critical')->count();
        $offline = count(array_filter($servers, fn (array $n) => $n['health'] === 'critical'));
        $warning = array_filter($nodes, fn (array $n) => in_array($n['health'], ['warning', 'critical'], true));

        $cpus = array_values(array_filter(array_map(fn (array $n) => $n['health'] === 'ok' ? ($n['details']['metrics']['cpu_percent'] ?? null) : null, $servers), 'is_numeric'));
        $cpu = $cpus === [] ? null : array_sum($cpus) / count($cpus);

        $state = match (true) {
            $critical > 0 || $offline > 0 => 'critical',
            $active > 0 || $warning !== [] => 'warning',
            $nodes === [] => 'unknown',
            default => 'ok',
        };

        return [
            'state' => $state,
            'incidents' => $active,
            'critical' => $critical,
            'offline' => $offline,
            'bpm' => $cpu === null ? 60 : (int) round(self::MIN_BPM + min(100, max(0, $cpu)) / 100 * (self::MAX_BPM - self::MIN_BPM)),
        ];
    }
}
