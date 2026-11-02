<?php

namespace App\Services\Intelligence;

/**
 * A possible explanation proposed by one rule, before scoring.
 *
 * - $base: the strongest level the rule allows for this kind of evidence.
 * - $basis: reason codes for that level (shown to the user).
 * - $supporting / $contradicting: evidence keys (E…) from the snapshot.
 * - $missing: codes of evidence that would be needed but is not stored.
 * - $sources: collectors the rule depends on (a failed or truncated one
 *   lowers confidence); $metricsOf: server key whose metric gaps matter.
 * - $rejected: set when the candidate is ruled out (e.g. it started
 *   after the incident); rejected candidates are kept for transparency.
 */
final class Candidate
{
    /**
     * @param  array<int, string>  $basis
     * @param  array<int, string>  $supporting
     * @param  array<int, string>  $contradicting
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $path
     * @param  array<int, string>  $sources
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $resource,
        public readonly string $base,
        public readonly array $basis = [],
        public readonly array $supporting = [],
        public readonly array $contradicting = [],
        public readonly array $missing = [],
        public readonly array $path = [],
        public readonly array $sources = [],
        public readonly ?string $metricsOf = null,
        public readonly ?string $rejected = null,
        public readonly array $params = [],
        public readonly int $priority = 5,
    ) {}
}
