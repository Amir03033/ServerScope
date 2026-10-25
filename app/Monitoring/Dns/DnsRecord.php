<?php

namespace App\Monitoring\Dns;

/**
 * A single resource record. $value is a normalised, comparable string
 * representation (e.g. "10 mx.example.com" for MX).
 */
final class DnsRecord
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $ttl,
        public readonly string $value,
        public readonly array $data = [],
    ) {}
}
