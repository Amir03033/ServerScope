<?php

namespace App\Services\Domains;

use App\Models\Domain;

/**
 * Aggregates the per-check states into one health status.
 */
class DomainStatus
{
    public static function calculate(Domain $domain): string
    {
        $expiryDays = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;

        $critical = ($domain->http_enabled && $domain->http_status === 'down')
            || ($domain->ssl_enabled && in_array($domain->ssl_status, Domain::SSL_INVALID_STATES, true))
            || ($domain->dns_enabled && $domain->dns_status === 'failed')
            || ($domain->expiry_enabled && $expiryDays !== null && $expiryDays < 0);

        if ($critical) {
            return 'critical';
        }

        $warning = ($domain->http_enabled && $domain->http_consecutive_failures > 0)
            || ($domain->ssl_enabled && in_array($domain->ssl_status, ['expiring', 'handshake_failed'], true))
            || ($domain->dns_enabled && $domain->dns_consecutive_failures > 0)
            || ($domain->expiry_enabled && $expiryDays !== null && $expiryDays <= max($domain->expiryWarningDays()));

        if ($warning) {
            return 'warning';
        }

        $checked = $domain->http_last_checked_at || $domain->ssl_last_checked_at || $domain->dns_last_checked_at;

        return $checked ? 'healthy' : 'unknown';
    }

    public static function refresh(Domain $domain): void
    {
        $status = self::calculate($domain);

        if ($domain->status !== $status) {
            $domain->forceFill(['status' => $status])->save();
        }
    }
}
