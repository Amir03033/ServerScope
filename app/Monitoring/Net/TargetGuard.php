<?php

namespace App\Monitoring\Net;

use App\Monitoring\Dns\DnsException;
use App\Monitoring\Dns\Resolver;

/**
 * Validates outbound monitoring targets and resolves them to a single
 * vetted IP address.
 *
 * - Only http/https URLs without credentials.
 * - Only allowed ports (80/443 by default).
 * - Hostnames are resolved through our own resolver; every A/AAAA answer
 *   must pass IpPolicy (a single private answer rejects the target, which
 *   defeats mixed-answer DNS rebinding).
 * - The caller connects to the returned IP, so the name cannot be
 *   re-resolved to a different address between check and use.
 */
class TargetGuard
{
    /**
     * @param  array<int, int>  $allowedPorts
     */
    public function __construct(
        private Resolver $resolver,
        private IpPolicy $policy,
        private array $allowedPorts = [80, 443],
    ) {}

    public function resolve(string $url): ResolvedTarget
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new BlockedTargetException('Ongeldige URL.', 'invalid_url');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedTargetException('Alleen http en https zijn toegestaan.', 'scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedTargetException('URL\'s met inloggegevens zijn niet toegestaan.', 'credentials');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, $this->allowedPorts, true)) {
            throw new BlockedTargetException("Poort {$port} is niet toegestaan.", 'port');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (! $this->policy->isAllowed($literal)) {
                throw new BlockedTargetException('Het doeladres is een intern of gereserveerd IP-adres.', 'private_ip');
            }

            return new ResolvedTarget($url, $scheme, $literal, $port, $literal, [$literal]);
        }

        $ascii = self::toAscii($host);

        if ($ascii === null) {
            throw new BlockedTargetException('Ongeldige hostname.', 'invalid_host');
        }

        // Single-label names, numeric-looking names and local suffixes
        // never point at public services.
        if (
            ! str_contains($ascii, '.')
            || preg_match('/(^|\.)(localhost|local|internal|intranet|lan|home\.arpa|localdomain)$/', $ascii)
            || preg_match('/^[0-9.]+$/', $ascii)
            || preg_match('/^0x[0-9a-f]+/i', $ascii)
        ) {
            throw new BlockedTargetException('De hostname verwijst niet naar een publiek adres.', 'invalid_host');
        }

        $addresses = $this->lookup($ascii);

        if ($addresses === []) {
            throw new BlockedTargetException('De hostname heeft geen A- of AAAA-records.', 'no_address');
        }

        foreach ($addresses as $address) {
            if (! $this->policy->isAllowed($address)) {
                throw new BlockedTargetException('De hostname verwijst naar een intern of gereserveerd IP-adres.', 'private_ip');
            }
        }

        // Prefer IPv4 for wider reachability from the monitoring host.
        $v4 = array_values(array_filter($addresses, fn ($ip) => ! str_contains($ip, ':')));
        $ip = $v4[0] ?? $addresses[0];

        return new ResolvedTarget($url, $scheme, $ascii, $port, $ip, $addresses);
    }

    /**
     * @return array<int, string>
     */
    private function lookup(string $host): array
    {
        $addresses = [];
        $errors = 0;

        foreach (['A', 'AAAA'] as $type) {
            try {
                $response = $this->resolver->query($host, $type);

                if ($response->isNxDomain()) {
                    throw new BlockedTargetException('Het domein bestaat niet (NXDOMAIN).', 'nxdomain');
                }

                array_push($addresses, ...$response->values($type));
            } catch (DnsException $exception) {
                $errors++;

                if ($errors === 2) {
                    throw new BlockedTargetException('DNS-resolutie mislukt: '.$exception->getMessage(), 'dns_error');
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public static function toAscii(string $host): ?string
    {
        if (preg_match('/^[a-z0-9.-]+$/', $host)) {
            $ascii = $host;
        } else {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI, INTL_IDNA_VARIANT_UTS46)
                : false;

            if ($ascii === false) {
                return null;
            }
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return strlen($ascii) <= 253 && preg_match('/\A'.$label.'(?:\.'.$label.')*\z/', $ascii) ? strtolower($ascii) : null;
    }
}
