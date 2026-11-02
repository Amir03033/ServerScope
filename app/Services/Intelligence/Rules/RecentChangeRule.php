<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * Deployments, audited configuration changes and agent updates before the
 * onset. Timing alone: low, except an agent update on the same server
 * shortly before that server's agent went silent (a known mechanism).
 */
final class RecentChangeRule implements Rule
{
    private const CODES = ['deployment' => 'recent_deployment', 'audit' => 'recent_config_change', 'agent_update' => 'recent_agent_update'];

    public function evaluate(InvestigationContext $context): array
    {
        $groups = [];

        foreach ($context->items(array_keys(self::CODES), fn ($i) => $i['phase'] === 'before' && $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $groups[self::CODES[$item['code']].'|'.$item['resource']][] = $key;
        }

        $candidates = [];

        foreach ($groups as $group => $keys) {
            [$code, $resource] = explode('|', $group, 2);
            $mechanism = $code === 'recent_agent_update' && $context->incident['source'] === 'agent' && $resource === $context->subject()
                && InvestigationContext::at($context->evidence[end($keys)])->gte($context->onset()->subHours(2));

            $candidates[] = new Candidate($code, $resource, $mechanism ? 'medium' : 'low', ['precedes_onset', $mechanism ? 'plausible_mechanism' : 'timing_only'], $keys,
                path: $context->path($resource), sources: ['deployments', 'audit_logs', 'agent_updates'], priority: 5);
        }

        return $candidates;
    }
}
