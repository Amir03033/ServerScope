<?php

namespace App\Services\Incidents\Evidence;

use App\Services\Incidents\Evidence\Collectors\AgentUpdateCollector;
use App\Services\Incidents\Evidence\Collectors\AuditCollector;
use App\Services\Incidents\Evidence\Collectors\DeploymentCollector;
use App\Services\Incidents\Evidence\Collectors\DnsChangeCollector;
use App\Services\Incidents\Evidence\Collectors\DomainCheckCollector;
use App\Services\Incidents\Evidence\Collectors\IncidentCollector;
use App\Services\Incidents\Evidence\Collectors\PageCheckCollector;
use App\Services\Incidents\Evidence\Collectors\PredictionCollector;
use App\Services\Incidents\Evidence\Collectors\ServiceCollector;

/**
 * The evidence collectors in their fixed order (the order matters for
 * events at the same moment: Time Machine's output depends on it).
 */
class Collectors
{
    public const ORDER = [
        IncidentCollector::class,
        ServiceCollector::class,
        DomainCheckCollector::class,
        DnsChangeCollector::class,
        PageCheckCollector::class,
        PredictionCollector::class,
        AgentUpdateCollector::class,
        DeploymentCollector::class,
        AuditCollector::class,
    ];

    /** @return array<int, Collector> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ORDER);
    }

    /**
     * Runs every collector. A collector that throws is reported through
     * $failed (when given) and skipped; without $failed the exception
     * propagates (Time Machine keeps its previous behaviour).
     *
     * @param  (callable(Collector, \Throwable): void)|null  $failed
     * @return array<int, EvidenceRecord>
     */
    public function collect(EvidenceScope $scope, ?callable $failed = null): array
    {
        $records = [];

        foreach ($this->all() as $collector) {
            if ($failed === null) {
                array_push($records, ...$collector->collect($scope));

                continue;
            }

            try {
                array_push($records, ...$collector->collect($scope));
            } catch (\Throwable $exception) {
                $failed($collector, $exception);
            }
        }

        return $records;
    }
}
