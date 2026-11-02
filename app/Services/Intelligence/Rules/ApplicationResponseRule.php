<?php

namespace App\Services\Intelligence\Rules;

use App\Services\Intelligence\Candidate;
use App\Services\Intelligence\InvestigationContext;

/**
 * A website or page that answered, but wrongly (status code, content,
 * header, redirect): the server and network worked, so the cause is most
 * likely in the application or its content (CorrelationEngine::failureKind
 * returns null for these, as no other resource can explain them).
 */
final class ApplicationResponseRule implements Rule
{
    public function evaluate(InvestigationContext $context): array
    {
        if ($context->failureKind() !== null || ! in_array($context->incident['source'], ['http', 'page'], true)) {
            return [];
        }

        $subject = $context->subject();
        $checks = array_keys($context->items('check_failed', fn ($i) => $i['resource'] === $subject));

        return [new Candidate('application_response', $subject, $checks !== [] ? 'medium' : 'low', ['server_answered'], array_values(array_filter([array_key_first($context->items('onset')), ...$checks])),
            missing: ['application_logs'], path: $context->path($subject), sources: ['domain_checks', 'page_checks'], priority: 2)];
    }
}
