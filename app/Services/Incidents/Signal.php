<?php

namespace App\Services\Incidents;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A monitoring condition that should be represented by an incident.
 * Repeated signals with the same fingerprint update one incident.
 */
final class Signal
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $source,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?Model $subject = null,
        public readonly array $context = [],
        // When this changes while the incident is active (e.g. a lower
        // expiry threshold was crossed), an escalation is announced.
        public readonly ?string $escalationKey = null,
        // When the problem began according to the evidence (MTTD).
        public readonly ?CarbonInterface $startedAt = null,
    ) {}
}
