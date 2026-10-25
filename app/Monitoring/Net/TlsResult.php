<?php

namespace App\Monitoring\Net;

use Carbon\CarbonImmutable;

/**
 * Result of a TLS inspection. $status is one of: valid, expired,
 * not_yet_valid, hostname_mismatch, untrusted, self_signed,
 * handshake_failed, connection_failed.
 */
final class TlsResult
{
    /**
     * @param  array<int, string>  $subjectAltNames
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?string $subject = null,
        public readonly ?string $issuer = null,
        public readonly array $subjectAltNames = [],
        public readonly ?CarbonImmutable $validFrom = null,
        public readonly ?CarbonImmutable $validTo = null,
        public readonly ?string $fingerprint = null,
        public readonly ?string $serial = null,
        public readonly ?bool $chainTrusted = null,
        public readonly ?bool $hostnameMatches = null,
        public readonly ?string $protocol = null,
        public readonly ?string $cipher = null,
        public readonly ?float $durationMs = null,
    ) {}

    public function hasCertificate(): bool
    {
        return $this->fingerprint !== null;
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }

    public function daysRemaining(?CarbonImmutable $now = null): ?int
    {
        if ($this->validTo === null) {
            return null;
        }

        return (int) floor(($now ?? CarbonImmutable::now())->diffInSeconds($this->validTo, false) / 86400);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'san' => $this->subjectAltNames,
            'valid_from' => $this->validFrom?->toIso8601String(),
            'valid_to' => $this->validTo?->toIso8601String(),
            'fingerprint' => $this->fingerprint,
            'serial' => $this->serial,
            'chain_trusted' => $this->chainTrusted,
            'hostname_matches' => $this->hostnameMatches,
            'protocol' => $this->protocol,
            'cipher' => $this->cipher,
        ];
    }
}
