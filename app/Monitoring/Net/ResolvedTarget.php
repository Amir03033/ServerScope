<?php

namespace App\Monitoring\Net;

/**
 * A URL whose host has been resolved and vetted. Connections must be made
 * to $ip only (pinned), never by resolving $host again.
 */
final class ResolvedTarget
{
    /**
     * @param  array<int, string>  $addresses  every address the host resolved to
     */
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
        public readonly array $addresses,
    ) {}
}
