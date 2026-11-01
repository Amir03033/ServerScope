<?php

namespace App\Services\Probes;

/**
 * HMAC-SHA256 request signatures between the probe and ServerScope.
 *
 * Signed string: METHOD \n path \n unix timestamp \n nonce \n sha256(body).
 * The receiver rejects a timestamp more than MAX_SKEW_SECONDS away and a
 * nonce it has seen before, so a captured request cannot be replayed.
 */
class ProbeSignature
{
    public const MAX_SKEW_SECONDS = 300;

    public const NONCE_PATTERN = '/\A[A-Za-z0-9]{16,64}\z/';

    public static function sign(string $secret, string $method, string $path, int $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), '/'.ltrim($path, '/'), $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }

    /** @return array<string, string> headers for a signed request */
    public static function headers(string $key, string $secret, string $method, string $path, string $body): array
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Probe-Key' => $key,
            'X-Probe-Timestamp' => (string) $timestamp,
            'X-Probe-Nonce' => $nonce,
            'X-Probe-Signature' => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }
}
