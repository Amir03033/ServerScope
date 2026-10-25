<?php

namespace App\Monitoring\Dns;

/**
 * Creates a resolver bound to one nameserver (used to compare answers
 * between resolvers for propagation checks).
 */
class ResolverFactory
{
    public function __construct(private float $timeoutSeconds = 3.0) {}

    public function forNameserver(string $ip): Resolver
    {
        return new DnsClient([$ip], $this->timeoutSeconds);
    }
}
