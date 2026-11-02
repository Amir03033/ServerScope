<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Incidents\CorrelationEngine;
use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * Another incident on the same resource or on something it depends on
 * (confirmed or structural edge, the edges correlation uses). It can only
 * explain this incident when it started first and its kind of failure can
 * cause this one (CorrelationEngine::CAUSES); otherwise it is at most a
 * weak signal. One that recovered before the onset, or started after it,
 * is rejected.
 */
final class UpstreamIncidentRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        $candidates = [];
        $seen = [];

        foreach ($context->items(['other_opened', 'other_ongoing'], fn ($i) => $context->isUpstreamOrSubject($i['resource'])) as $key => $item) {
            $id = (int) $item['params']['id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $started = InvestigationContext::at(['at' => $item['params']['started_at'] ?? $item['at']]);
            $resolved = array_key_first($context->items('other_resolved', fn ($i) => (int) $i['params']['id'] === $id));
            $resolvedAt = $resolved !== null ? InvestigationContext::at($context->evidence[$resolved]) : null;
            $source = (string) ($item['params']['source'] ?? '');
            $mechanism = $context->failureKind() !== null && in_array($context->failureKind(), CorrelationEngine::CAUSES[$source] ?? [], true);
            $same = $item['resource'] === $context->subject();
            $code = $mechanism ? 'upstream_incident' : 'upstream_signal';
            $params = ['incident_id' => $id, 'source' => $source, 'same_resource' => $same];

            $supporting = [$key];
            $contradicting = [];
            $basis = ['precedes_onset', $same ? 'same_resource' : 'confirmed_path', $mechanism ? 'plausible_mechanism' : 'no_known_mechanism'];

            // ServerScope itself grouped this incident under that one.
            foreach ($context->items('grouped_under', fn ($i) => (int) ($i['params']['cause_incident_id'] ?? 0) === $id || (int) ($i['params']['parent_id'] ?? 0) === $id) as $groupKey => $group) {
                $supporting[] = $groupKey;
                $basis[] = 'correlated_by_serverscope';
            }

            if ($started !== null && $started->gt($context->onset())) {
                $candidates[] = new Candidate($code, $item['resource'], 'low', $basis, $supporting, path: $context->path($item['resource']), sources: ['incidents'], rejected: 'after_onset', params: $params);

                continue;
            }

            if ($resolvedAt !== null && $resolvedAt->lt($context->onset())) {
                $candidates[] = new Candidate($code, $item['resource'], 'low', $basis, $supporting, [$resolved], path: $context->path($item['resource']), sources: ['incidents'], rejected: 'recovered_before_onset', params: $params);

                continue;
            }

            // The other resource's own checks kept succeeding during this incident.
            $checks = $context->checks((string) $item['resource']);

            if (! $same && $checks !== null && ($checks['params']['during_ok'] ?? 0) > 0 && ($checks['params']['during_failed'] ?? 0) === 0) {
                $contradicting[] = $checks['key'];
            }

            // An agent incident upstream while other websites on that server kept answering: the host was up.
            if ($source === 'agent') {
                foreach ($context->items('dependent_checks', fn ($i) => $i['params']['server'] === $item['resource'] && $i['params']['ok'] > 0 && $i['params']['failed'] === 0) as $depKey => $dep) {
                    $contradicting[] = $depKey;
                }
            }

            $candidates[] = new Candidate($code, $item['resource'], $mechanism ? 'high' : 'low', $basis, $supporting, $contradicting,
                path: $context->path($item['resource']), sources: ['incidents', 'domain_checks', 'page_checks'], params: $params, priority: $mechanism ? 1 : 6);
        }

        return $candidates;
    }
}
