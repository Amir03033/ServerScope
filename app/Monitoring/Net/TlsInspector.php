<?php

namespace App\Monitoring\Net;

use Carbon\CarbonImmutable;

/**
 * Connects to a vetted IP with TLS (SNI = hostname) and inspects the
 * certificate. A strict handshake decides whether the chain is trusted
 * and the hostname matches; if that fails, a second, non-verifying
 * handshake retrieves the certificate so the reason can be reported.
 */
class TlsInspector
{
    public function __construct(
        private ?string $caFile = null,
    ) {}

    public function inspect(string $host, string $ip, int $port = 443, float $timeoutSeconds = 10.0): TlsResult
    {
        $started = microtime(true);

        $strict = $this->handshake($host, $ip, $port, $timeoutSeconds, true);

        if ($strict['certificate'] !== null) {
            return $this->analyse($host, $strict, true, $started);
        }

        if ($strict['connect_failed']) {
            return new TlsResult('connection_failed', 'Geen TCP-verbinding mogelijk: '.$strict['error'], durationMs: $this->ms($started));
        }

        $lenient = $this->handshake($host, $ip, $port, $timeoutSeconds, false);

        if ($lenient['certificate'] === null) {
            return new TlsResult('handshake_failed', 'TLS-handshake mislukt: '.($lenient['error'] ?: $strict['error']), durationMs: $this->ms($started));
        }

        return $this->analyse($host, $lenient, false, $started, $strict['error']);
    }

    /**
     * @return array{certificate: mixed, chain: array, protocol: ?string, cipher: ?string, error: string, connect_failed: bool}
     */
    private function handshake(string $host, string $ip, int $port, float $timeout, bool $verify): array
    {
        $options = [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => ! $verify,
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'security_level' => 1,
        ];

        if ($verify && $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $context = stream_context_create(['ssl' => $options]);

        // TCP first, so connection failures are distinguishable from TLS ones.
        $socket = @stream_socket_client("tcp://{$address}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return ['certificate' => null, 'chain' => [], 'protocol' => null, 'cipher' => null, 'error' => $error ?: 'verbinding geweigerd', 'connect_failed' => true];
        }

        stream_set_timeout($socket, (int) ceil($timeout));
        error_clear_last();

        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($crypto !== true) {
            $message = error_get_last()['message'] ?? 'handshake mislukt';
            fclose($socket);

            return ['certificate' => null, 'chain' => [], 'protocol' => null, 'cipher' => null, 'error' => self::cleanError($message), 'connect_failed' => false];
        }

        $params = stream_context_get_params($socket)['options']['ssl'] ?? [];
        $meta = stream_get_meta_data($socket)['crypto'] ?? [];
        fclose($socket);

        return [
            'certificate' => $params['peer_certificate'] ?? null,
            'chain' => $params['peer_certificate_chain'] ?? [],
            'protocol' => $meta['protocol'] ?? null,
            'cipher' => $meta['cipher_name'] ?? null,
            'error' => '',
            'connect_failed' => false,
        ];
    }

    private function analyse(string $host, array $handshake, bool $trusted, float $started, string $verifyError = ''): TlsResult
    {
        $info = openssl_x509_parse($handshake['certificate']) ?: [];
        $fingerprint = openssl_x509_fingerprint($handshake['certificate'], 'sha256') ?: null;

        $sans = self::subjectAltNames($info);
        $validFrom = isset($info['validFrom_time_t']) ? CarbonImmutable::createFromTimestampUTC($info['validFrom_time_t']) : null;
        $validTo = isset($info['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC($info['validTo_time_t']) : null;
        $subject = self::dn($info['subject'] ?? []);
        $issuer = self::dn($info['issuer'] ?? []);
        $matches = self::hostnameMatches($host, $sans, $info['subject']['CN'] ?? null);
        $selfSigned = ($info['subject'] ?? null) === ($info['issuer'] ?? null);
        $now = CarbonImmutable::now();

        [$status, $message] = match (true) {
            $validTo !== null && $validTo->lt($now) => ['expired', 'Het certificaat is verlopen op '.$validTo->format('d-m-Y H:i').' UTC.'],
            $validFrom !== null && $validFrom->gt($now) => ['not_yet_valid', 'Het certificaat is pas geldig vanaf '.$validFrom->format('d-m-Y H:i').' UTC.'],
            ! $matches => ['hostname_mismatch', "Het certificaat is niet uitgegeven voor {$host}."],
            ! $trusted && $selfSigned => ['self_signed', 'Het certificaat is zelfondertekend en wordt niet vertrouwd.'],
            ! $trusted => ['untrusted', 'De certificaatketen wordt niet vertrouwd'.($verifyError !== '' ? " ({$verifyError})" : '').'.'],
            default => ['valid', null],
        };

        return new TlsResult(
            status: $status,
            message: $message,
            subject: $subject,
            issuer: $issuer,
            subjectAltNames: $sans,
            validFrom: $validFrom,
            validTo: $validTo,
            fingerprint: $fingerprint,
            serial: $info['serialNumberHex'] ?? ($info['serialNumber'] ?? null),
            chainTrusted: $trusted,
            hostnameMatches: $matches,
            protocol: $handshake['protocol'],
            cipher: $handshake['cipher'],
            durationMs: $this->ms($started),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function subjectAltNames(array $info): array
    {
        $names = [];

        foreach (explode(',', $info['extensions']['subjectAltName'] ?? '') as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            } elseif (str_starts_with($entry, 'IP Address:')) {
                $names[] = substr($entry, 11);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * RFC 6125: match against SAN DNS names; a wildcard covers exactly one
     * left-most label. The CN is only used when no SAN is present.
     */
    public static function hostnameMatches(string $host, array $sans, ?string $commonName = null): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $candidates = $sans !== [] ? $sans : array_filter([$commonName !== null ? strtolower($commonName) : null]);

        foreach ($candidates as $pattern) {
            $pattern = rtrim($pattern, '.');

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $suffix = substr($pattern, 1);
                $label = substr($host, 0, -strlen($suffix));

                if (str_ends_with($host, $suffix) && $label !== '' && ! str_contains($label, '.') && substr_count($suffix, '.') >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function dn(array $parts): ?string
    {
        if ($parts === []) {
            return null;
        }

        $pairs = [];

        foreach (['CN', 'O', 'OU', 'C'] as $key) {
            if (isset($parts[$key])) {
                $pairs[] = $key.'='.(is_array($parts[$key]) ? implode('/', $parts[$key]) : $parts[$key]);
            }
        }

        return implode(', ', $pairs) ?: null;
    }

    private static function cleanError(string $message): string
    {
        $message = preg_replace('/^stream_socket_enable_crypto\(\): /', '', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }

    private function ms(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 1);
    }
}
