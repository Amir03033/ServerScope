<?php

namespace App\Services\Incidents\Evidence;

use Carbon\CarbonImmutable;

/**
 * One observed event around an incident, in a language-independent form:
 * a stable code with parameters, the resource it concerns ("type:id" in
 * the scope) and the stored row it comes from. Time Machine renders it as
 * a sentence; NEXUS Intelligence stores it as evidence.
 *
 * $detail is text from monitoring or users (an error message, a service
 * state, an audit description): untrusted, shown escaped, never used to
 * decide anything.
 */
final class EvidenceRecord
{
    public const CATEGORIES = ['incident', 'change', 'anomaly', 'service', 'check', 'recovery'];

    /**
     * @param  array<string, scalar|null>  $params
     * @param  array{type: string, id: int}|null  $source
     */
    public function __construct(
        public readonly CarbonImmutable $at,
        public readonly string $category,
        public readonly string $code,
        public readonly array $params = [],
        public readonly ?string $resource = null,
        public readonly bool $problem = false,
        public readonly ?string $detail = null,
        public readonly ?string $url = null,
        public readonly ?array $source = null,
    ) {}
}
