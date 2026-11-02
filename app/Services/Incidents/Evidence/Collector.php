<?php

namespace App\Services\Incidents\Evidence;

/**
 * Reads one kind of stored monitoring data for the resources in scope.
 * Every query is limited to the scope's team (explicitly, not through a
 * global scope: queue jobs run without a tenant), its resources, its time
 * window and a row limit (noted on the scope when reached).
 */
interface Collector
{
    /** Stable name, used in collector status and truncation notes. */
    public function name(): string;

    /** @return array<int, EvidenceRecord> */
    public function collect(EvidenceScope $scope): array;
}
