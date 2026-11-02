<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/** An uptime reset (reboot) of a server in scope shortly before or at the onset. */
final class RebootRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        $from = $context->onset()->subMinutes(30);
        $to = $context->onset()->addMinutes(10);
        $byServer = [];

        foreach ($context->items('reboot', fn ($i) => $context->isUpstreamOrSubject($i['resource']) && InvestigationContext::at($i)->between($from, $to)) as $key => $item) {
            $byServer[$item['resource']][] = $key;
        }

        return array_map(fn (string $server) => new Candidate('reboot', $server, $context->failureKind() === 'availability' ? 'medium' : 'low', ['observed_restart', 'near_onset'],
            $byServer[$server], path: $context->path($server), sources: ['metrics'], metricsOf: $server, priority: 3), array_keys($byServer));
    }
}
