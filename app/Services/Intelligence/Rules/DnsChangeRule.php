<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/** DNS records of a domain in scope changed before the onset. */
final class DnsChangeRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        $byDomain = [];

        foreach ($context->items('dns_changed', fn ($i) => $context->isUpstreamOrSubject($i['resource']) && $i['phase'] === 'before') as $key => $item) {
            $byDomain[$item['resource']][] = $key;
        }

        $relevant = in_array($context->failureKind(), ['dns', 'availability'], true);

        return array_map(fn (string $domain) => new Candidate('dns_change', $domain, $context->failureKind() === 'dns' ? 'medium' : 'low',
            ['precedes_onset', $relevant ? 'plausible_mechanism' : 'no_known_mechanism', 'timing_only'], $byDomain[$domain],
            path: $context->path($domain), sources: ['dns_changes'], priority: 4), array_keys($byDomain));
    }
}
