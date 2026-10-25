<?php

namespace App\Monitoring\Dns;

interface Resolver
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SOA'];

    /**
     * @throws DnsException on timeouts, network errors and malformed responses
     */
    public function query(string $name, string $type): DnsResponse;
}
