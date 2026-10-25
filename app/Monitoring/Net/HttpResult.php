<?php

namespace App\Monitoring\Net;

/**
 * Outcome of a safe HTTP request. On failure $error holds a machine
 * readable reason (timeout, connection_failed, tls_error, dns_error,
 * blocked, redirect_loop, too_many_redirects, ...).
 */
final class HttpResult
{
    /**
     * @param  array<int, array{url: string, status: int}>  $redirects
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $finalUrl = null,
        public readonly ?int $status = null,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly bool $truncated = false,
        public readonly array $redirects = [],
        public readonly ?float $durationMs = null,
        public readonly ?string $ip = null,
        public readonly ?string $error = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
