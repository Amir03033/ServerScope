<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\AgentUpdateRequest;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;

/** Finished agent updates of the servers in scope. */
final class AgentUpdateCollector implements Collector
{
    private const MAX_ROWS = 50;

    public function name(): string
    {
        return 'agent_updates';
    }

    public function collect(EvidenceScope $scope): array
    {
        if (! isset($scope->byType['server'])) {
            return [];
        }

        $updates = AgentUpdateRequest::query()->withoutGlobalScopes()->where('team_id', $scope->teamId())->whereIn('server_id', array_keys($scope->byType['server']))
            ->whereNotNull('finished_at')->whereBetween('finished_at', [$scope->window['from'], $scope->window['to']])->limit(self::MAX_ROWS)->get();

        if ($updates->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        return $updates->map(fn (AgentUpdateRequest $update) => new EvidenceRecord(CarbonImmutable::instance($update->finished_at), 'change', 'agent_update',
            ['from' => $update->from_version, 'to' => $update->target_version, 'status' => $update->status],
            $scope->key('server', $update->server_id), source: ['type' => 'agent_update_request', 'id' => (int) $update->id]))->all();
    }
}
