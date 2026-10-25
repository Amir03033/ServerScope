<?php

namespace Tests\Support;

use App\Monitoring\Dns\DnsException;
use App\Monitoring\Dns\DnsRecord;
use App\Monitoring\Dns\DnsResponse;
use App\Monitoring\Dns\Resolver;

/**
 * In-memory resolver for tests: map "name|TYPE" to values, an rcode or
 * an exception.
 */
class FakeResolver implements Resolver
{
    /** @var array<string, array<int, string>|int|\Throwable> */
    public array $records = [];

    /** @var array<int, string> */
    public array $queries = [];

    public function set(string $name, string $type, array|int|\Throwable $value): static
    {
        $this->records[strtolower($name).'|'.strtoupper($type)] = $value;

        return $this;
    }

    /** Point a host at IPv4 addresses (and no AAAA). */
    public function host(string $name, string ...$ips): static
    {
        return $this->set($name, 'A', $ips)->set($name, 'AAAA', []);
    }

    public function query(string $name, string $type): DnsResponse
    {
        $key = strtolower(rtrim($name, '.')).'|'.strtoupper($type);
        $this->queries[] = $key;
        $value = $this->records[$key] ?? [];

        if ($value instanceof \Throwable) {
            throw $value;
        }

        if (is_int($value)) {
            return new DnsResponse($value, []);
        }

        return new DnsResponse(0, array_map(
            fn (string $v) => new DnsRecord(strtolower($name), strtoupper($type), 300, $v),
            $value,
        ));
    }

    public static function timeout(): DnsException
    {
        return new DnsException('Nameserver timed out.', 'timeout');
    }
}
