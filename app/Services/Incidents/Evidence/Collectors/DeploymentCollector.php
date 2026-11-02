<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\Deployment;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Deployments (recorded through the API) linked to any resource in scope. */
final class DeploymentCollector implements Collector
{
    private const MAX_ROWS = 50;

    public function name(): string
    {
        return 'deployments';
    }

    public function collect(EvidenceScope $scope): array
    {
        $byType = $scope->byType;
        $links = $byType === [] ? collect() : DB::table('deployment_resources')->where(function ($q) use ($byType) {
            foreach ($byType as $type => $items) {
                $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', array_keys($items)));
            }
        })->get(['deployment_id', 'resource_type', 'resource_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $deployments = Deployment::query()->withoutGlobalScopes()->where('team_id', $scope->teamId())
            ->whereIn('id', $links->pluck('deployment_id')->unique())->whereBetween('deployed_at', [$scope->window['from'], $scope->window['to']])->limit(self::MAX_ROWS)->get();

        if ($deployments->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        $records = [];

        foreach ($deployments as $deployment) {
            $link = $links->firstWhere('deployment_id', $deployment->id);
            $records[] = new EvidenceRecord(CarbonImmutable::instance($deployment->deployed_at), 'change', 'deployment', ['label' => $deployment->label(), 'environment' => $deployment->environment],
                $scope->key($link->resource_type, $link->resource_id), detail: $deployment->commit_sha ? substr($deployment->commit_sha, 0, 12) : null,
                url: route('deployments.show', $deployment), source: ['type' => 'deployment', 'id' => (int) $deployment->id]);
        }

        return $records;
    }
}
