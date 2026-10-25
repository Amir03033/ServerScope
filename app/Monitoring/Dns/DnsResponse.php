<?php

namespace App\Monitoring\Dns;

final class DnsResponse
{
    public const NOERROR = 0;

    public const SERVFAIL = 2;

    public const NXDOMAIN = 3;

    public const REFUSED = 5;

    /**
     * @param  array<int, DnsRecord>  $answers
     */
    public function __construct(
        public readonly int $rcode,
        public readonly array $answers,
        public readonly bool $truncated = false,
        public readonly ?string $nameserver = null,
    ) {}

    public function isNxDomain(): bool
    {
        return $this->rcode === self::NXDOMAIN;
    }

    public function isSuccess(): bool
    {
        return $this->rcode === self::NOERROR;
    }

    /**
     * Records of the requested type only (answers may include CNAMEs).
     *
     * @return array<int, DnsRecord>
     */
    public function recordsOfType(string $type): array
    {
        return array_values(array_filter($this->answers, fn (DnsRecord $r) => $r->type === $type));
    }

    /**
     * Sorted, de-duplicated values of the requested type.
     *
     * @return array<int, string>
     */
    public function values(string $type): array
    {
        $values = array_values(array_unique(array_map(fn (DnsRecord $r) => $r->value, $this->recordsOfType($type))));
        sort($values, SORT_STRING);

        return $values;
    }
}
