<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Net\IpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpPolicyTest extends TestCase
{
    public static function blocked(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '127.255.255.254', '0.0.0.0', '10.1.2.3', '172.16.0.1', '172.31.255.255',
            '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.255', '192.0.0.8',
            '192.0.2.1', '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250',
            '240.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1',
            '64:ff9b::a9fe:a9fe', '2002:7f00:1::', '2001:0:4136:e378::1', '2001:db8::1', 'fc00::1',
            'fd00:ec2::254', 'fe80::1', 'fec0::1', 'ff02::1', '100::1', '3fff::1',
            'not-an-ip', '1.2.3', '01.02.03.04', '',
        ]);
    }

    public static function allowed(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '1.1.1.1', '8.8.8.8', '140.82.112.3', '172.15.255.255', '172.32.0.1', '100.128.0.1',
            '2606:4700:4700::1111', '2a00:1450:4001:80b::200e',
        ]);
    }

    #[DataProvider('blocked')]
    public function test_blocked_addresses(string $ip): void
    {
        $this->assertFalse((new IpPolicy)->isAllowed($ip), "{$ip} must be blocked");
    }

    #[DataProvider('allowed')]
    public function test_public_addresses(string $ip): void
    {
        $this->assertTrue((new IpPolicy)->isAllowed($ip), "{$ip} must be allowed");
    }

    public function test_explicit_exceptions(): void
    {
        $policy = new IpPolicy(false, ['10.20.0.0/16']);

        $this->assertTrue($policy->isAllowed('10.20.3.4'));
        $this->assertFalse($policy->isAllowed('10.21.0.1'));
        $this->assertTrue((new IpPolicy(true))->isAllowed('127.0.0.1'));
    }
}
