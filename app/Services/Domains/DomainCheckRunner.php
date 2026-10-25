<?php

namespace App\Services\Domains;

use App\Models\Domain;
use InvalidArgumentException;

class DomainCheckRunner
{
    public const TYPES = ['http', 'ssl', 'dns', 'expiry'];

    public function __construct(
        private HttpMonitor $http,
        private SslMonitor $ssl,
        private DnsMonitor $dns,
        private ExpiryMonitor $expiry,
    ) {}

    public function run(Domain $domain, string $type): void
    {
        match ($type) {
            'http' => $this->http->check($domain),
            'ssl' => $this->ssl->check($domain),
            'dns' => $this->dns->check($domain),
            'expiry' => $this->expiry->check($domain),
            default => throw new InvalidArgumentException("Unknown check type {$type}."),
        };
    }

    /** Whether a check type applies to the domain's configuration. */
    public static function applies(Domain $domain, string $type): bool
    {
        return match ($type) {
            'http' => $domain->http_enabled,
            'ssl' => $domain->ssl_enabled && $domain->scheme === 'https',
            'dns' => $domain->dns_enabled,
            'expiry' => $domain->expiry_enabled,
            default => false,
        };
    }

    /** Minutes between runs of a check type for the domain. */
    public static function interval(Domain $domain, string $type): int
    {
        return match ($type) {
            'http' => max(1, $domain->check_interval_minutes),
            'ssl' => max($domain->check_interval_minutes, (int) config('serverscope.monitoring.ssl_interval_minutes', 360)),
            'dns' => max($domain->check_interval_minutes, (int) config('serverscope.monitoring.dns_interval_minutes', 15)),
            // The expiry monitor sets its own next run; this only guards
            // against dispatching twice while a lookup is running.
            'expiry' => 60,
        };
    }
}
