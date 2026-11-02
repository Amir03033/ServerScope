<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * A maintenance window on the resource or something it depends on that
 * covered the onset. ServerScope does not open incidents for a resource in
 * maintenance itself, but dependent resources keep being checked.
 */
final class MaintenanceRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        $onset = $context->onset();
        $candidates = [];

        foreach ($context->items('maintenance', fn ($i) => $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $start = InvestigationContext::at($item);
            $end = isset($item['params']['ends_at']) ? InvestigationContext::at(['at' => $item['params']['ends_at']]) : null;

            if ($start !== null && $start->lte($onset) && ($end === null || $end->gt($onset))) {
                $candidates[] = new Candidate('planned_maintenance', $item['resource'], 'medium', ['maintenance_window'], [$key], path: $context->path($item['resource']), sources: ['maintenance'], priority: 1);
            }
        }

        return $candidates;
    }
}
