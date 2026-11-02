<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\Prediction;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;

/** Anomalies and forecasts the hourly analysis stored (it is never re-run here). */
final class PredictionCollector implements Collector
{
    private const MAX_ROWS = 50;

    public function name(): string
    {
        return 'predictions';
    }

    public function collect(EvidenceScope $scope): array
    {
        $records = [];

        foreach ($scope->resources as $resource) {
            if (! in_array($resource['type'], ['server', 'domain'], true)) {
                continue;
            }

            $predictions = Prediction::query()->withoutGlobalScopes()->where('team_id', $scope->teamId())->forResource($resource['model'])
                ->whereBetween('detected_at', [$scope->window['from'], $scope->window['to']])->limit(self::MAX_ROWS)->get();

            if ($predictions->count() >= self::MAX_ROWS) {
                $scope->truncate($this->name());
            }

            foreach ($predictions as $prediction) {
                $records[] = new EvidenceRecord(CarbonImmutable::instance($prediction->detected_at), 'anomaly', $prediction->kind === 'anomaly' ? 'anomaly' : 'forecast',
                    ['metric' => $prediction->metric, 'dimension' => $prediction->dimension, 'severity' => $prediction->severity, 'confidence' => $prediction->confidence],
                    $resource['key'], $prediction->kind === 'anomaly', mb_strimwidth((string) $prediction->reason, 0, 200, '…'), source: ['type' => 'prediction', 'id' => (int) $prediction->id]);
            }
        }

        return $records;
    }
}
