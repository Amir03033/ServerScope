<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * A service on a server in scope stopped or failed before the onset and
 * had not recovered by then. Strong only with a confirmed dependency on
 * the service itself; a service that merely runs on a server the resource
 * depends on may be unrelated (structural exposure, medium at most).
 */
final class ServiceFailureRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        if (in_array($context->failureKind(), ['tls', 'dns'], true)) {
            return [];
        }

        $candidates = [];
        $byService = [];

        foreach ($context->items('service', fn ($i) => $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $byService[$item['resource']][$key] = $item;
        }

        foreach ($byService as $service => $items) {
            $onset = $context->onset();
            $failing = array_filter($items, fn ($i) => $i['problem'] && ! InvestigationContext::at($i)->gt($onset));
            $lastFailing = array_key_last($failing);

            if ($lastFailing === null) {
                $after = array_key_first(array_filter($items, fn ($i) => $i['problem']));

                if ($after !== null) {
                    $candidates[] = new Candidate('service_failed', $service, 'low', ['structural_path'], [$after], path: $context->path($service), sources: ['services'], rejected: 'after_onset');
                }

                continue;
            }

            $failedAt = InvestigationContext::at($items[$lastFailing]);
            $recovery = array_key_first(array_filter($items, fn ($i) => ! $i['problem'] && InvestigationContext::at($i)->gte($failedAt) && InvestigationContext::at($i)->lte($onset)));

            if ($recovery !== null) {
                $candidates[] = new Candidate('service_failed', $service, 'low', ['structural_path'], [$lastFailing], [$recovery], path: $context->path($service), sources: ['services'], rejected: 'recovered_before_onset');

                continue;
            }

            $direct = $context->relation($service) === 'upstream';
            $base = $context->failureKind() === null ? 'low' : ($direct ? 'high' : 'medium');

            $candidates[] = new Candidate('service_failed', $service, $base, ['precedes_onset', $direct ? 'confirmed_path' : 'structural_only', $context->failureKind() === null ? 'no_known_mechanism' : 'plausible_mechanism'],
                [$lastFailing], path: $context->path($service), sources: ['services'], priority: 2);
        }

        return $candidates;
    }
}
