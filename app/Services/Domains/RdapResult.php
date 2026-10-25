<?php

namespace App\Services\Domains;

use Carbon\CarbonImmutable;

/**
 * status:
 * - verified     the registry returned an expiration event
 * - unavailable  the registry answered but publishes no expiration date
 * - unsupported  no RDAP service is known for the TLD
 * - not_found    the registry does not know the domain
 * - rate_limited the registry asked us to slow down (HTTP 429)
 * - error        network/parse error
 */
final class RdapResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?CarbonImmutable $registeredAt = null,
        public readonly ?string $registrar = null,
        public readonly ?string $source = null,
        public readonly ?string $registrableDomain = null,
        public readonly array $statuses = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {}
}
