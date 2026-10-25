<?php

namespace Tests\Support;

use App\Monitoring\Net\TlsInspector;
use App\Monitoring\Net\TlsResult;
use Carbon\CarbonImmutable;

class FakeTlsInspector extends TlsInspector
{
    public ?TlsResult $result = null;

    public array $calls = [];

    public function inspect(string $host, string $ip, int $port = 443, float $timeoutSeconds = 10.0): TlsResult
    {
        $this->calls[] = [$host, $ip, $port];

        return $this->result ?? self::certificate('valid', 90);
    }

    public static function certificate(string $status, int $daysRemaining, string $fingerprint = 'aa:bb', ?string $message = null): TlsResult
    {
        return new TlsResult(
            status: $status,
            message: $message,
            subject: 'CN=example.com',
            issuer: 'CN=R11, O=Let\'s Encrypt, C=US',
            subjectAltNames: ['example.com', 'www.example.com'],
            validFrom: CarbonImmutable::now()->subDays(30),
            validTo: CarbonImmutable::now()->addDays($daysRemaining)->addHour(),
            fingerprint: $fingerprint,
            serial: '01',
            chainTrusted: $status === 'valid',
            hostnameMatches: $status !== 'hostname_mismatch',
            protocol: 'TLSv1.3',
            cipher: 'TLS_AES_256_GCM_SHA384',
            durationMs: 42.0,
        );
    }
}
