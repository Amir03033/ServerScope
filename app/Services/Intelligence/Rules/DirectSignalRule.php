<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * Incidents whose own check names the problem: an invalid or expiring
 * certificate, an expiring domain registration, failing DNS resolution.
 * The observation is direct; what led to it (a missed renewal, a changed
 * zone) is not known to ServerScope.
 */
final class DirectSignalRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        $source = $context->incident['source'];
        $subject = $context->subject();
        $onset = array_key_first($context->items('onset'));

        $code = match (true) {
            in_array($source, ['ssl', 'ssl_expiry'], true) || $context->failureKind() === 'tls' => 'certificate_problem',
            $source === 'expiry' => 'registration_expiry',
            $source === 'dns' || $context->failureKind() === 'dns' => 'dns_resolution_failure',
            default => null,
        };

        if ($code === null) {
            return [];
        }

        $type = ['certificate_problem' => 'ssl', 'registration_expiry' => 'expiry', 'dns_resolution_failure' => 'dns'][$code];
        $checks = array_keys($context->items('check_failed', fn ($i) => $i['resource'] === $subject && ($i['params']['type'] ?? null) === $type));

        return [new Candidate($code, $subject, $checks !== [] || $source !== 'http' ? 'high' : 'medium', ['direct_observation'], array_values(array_filter([$onset, ...$checks])),
            missing: ['underlying_change'], path: $context->path($subject), sources: ['incidents', 'domain_checks'], priority: 0)];
    }
}
