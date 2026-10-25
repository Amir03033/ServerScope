<?php

namespace App\Monitoring\Net;

/**
 * Decides whether an IP address may be contacted by outbound monitoring.
 *
 * Only globally routable unicast addresses are allowed. Private, loopback,
 * link-local (including cloud metadata such as 169.254.169.254 and
 * fd00:ec2::254), CGNAT, multicast, documentation, benchmarking and
 * reserved ranges are rejected, as are IPv6 transition formats that embed
 * an IPv4 address (IPv4-mapped, IPv4-compatible, NAT64, 6to4, Teredo).
 */
class IpPolicy
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '::/96', '64:ff9b::/96', '64:ff9b:1::/48',
        '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * @param  array<int, string>  $allowedCidrs  explicit exceptions (e.g. for local testing)
     */
    public function __construct(
        private bool $allowPrivate = false,
        private array $allowedCidrs = [],
    ) {}

    public function isAllowed(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        foreach ($this->allowedCidrs as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return true;
            }
        }

        if ($this->allowPrivate) {
            return true;
        }

        $ranges = strlen($binary) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return false;
            }
        }

        // IPv6: only global unicast (2000::/3) is routable on the internet.
        if (strlen($binary) === 16 && (ord($binary[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return true;
    }

    public static function inCidr(string $binaryIp, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $networkBinary = @inet_pton($network);

        if ($networkBinary === false || strlen($networkBinary) !== strlen($binaryIp)) {
            return false;
        }

        $bits = $bits === null ? strlen($binaryIp) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($binaryIp, $networkBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binaryIp[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
