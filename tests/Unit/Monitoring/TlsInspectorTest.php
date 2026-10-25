<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Net\TlsInspector;
use PHPUnit\Framework\TestCase;

class TlsInspectorTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/serverscope-tls-'.getmypid();
        @mkdir(self::$dir);
    }

    public function test_hostname_matching_follows_rfc_6125(): void
    {
        $this->assertTrue(TlsInspector::hostnameMatches('example.com', ['example.com']));
        $this->assertTrue(TlsInspector::hostnameMatches('WWW.Example.com.', ['www.example.com']));
        $this->assertTrue(TlsInspector::hostnameMatches('www.example.com', ['*.example.com']));
        $this->assertFalse(TlsInspector::hostnameMatches('example.com', ['*.example.com']));
        $this->assertFalse(TlsInspector::hostnameMatches('a.b.example.com', ['*.example.com']));
        $this->assertFalse(TlsInspector::hostnameMatches('example.com', ['*.com']));
        $this->assertFalse(TlsInspector::hostnameMatches('evil-example.com', ['example.com']));
        // CN is only considered without SANs.
        $this->assertTrue(TlsInspector::hostnameMatches('legacy.example', [], 'legacy.example'));
        $this->assertFalse(TlsInspector::hostnameMatches('legacy.example', ['other.example'], 'legacy.example'));
    }

    public function test_extracts_subject_alt_names(): void
    {
        $info = ['extensions' => ['subjectAltName' => 'DNS:example.com, DNS:*.Example.com, IP Address:192.0.2.1']];

        $this->assertSame(['example.com', '*.example.com', '192.0.2.1'], TlsInspector::subjectAltNames($info));
    }

    public function test_connection_failure_is_reported(): void
    {
        // Nothing listens on this port.
        $result = (new TlsInspector)->inspect('example.com', '127.0.0.1', 1, 2);

        $this->assertSame('connection_failed', $result->status);
        $this->assertFalse($result->hasCertificate());
    }

    public function test_self_signed_certificate(): void
    {
        [$cert, $key] = $this->selfSigned('tls.example.test', 30);

        $result = $this->inspectAgainst($cert, $key, 'tls.example.test');

        $this->assertSame('self_signed', $result->status);
        $this->assertFalse($result->chainTrusted);
        $this->assertTrue($result->hostnameMatches);
        $this->assertSame(['tls.example.test'], $result->subjectAltNames);
        $this->assertNotNull($result->fingerprint);
        $this->assertContains($result->daysRemaining(), [29, 30]);
    }

    public function test_hostname_mismatch(): void
    {
        [$cert, $key] = $this->selfSigned('other.example.test', 30);

        $result = $this->inspectAgainst($cert, $key, 'tls.example.test');

        $this->assertSame('hostname_mismatch', $result->status);
        $this->assertFalse($result->hostnameMatches);
    }

    public function test_expired_certificate(): void
    {
        [$cert, $key] = $this->selfSigned('tls.example.test', -1);

        $result = $this->inspectAgainst($cert, $key, 'tls.example.test');

        $this->assertSame('expired', $result->status);
        $this->assertLessThan(0, $result->daysRemaining());
    }

    public function test_certificate_signed_by_trusted_ca_is_valid(): void
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $caCsr = openssl_csr_new(['commonName' => 'ServerScope Test CA'], $caKey);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 30, ['x509_extensions' => 'v3_ca', 'config' => $this->opensslConfig('ca.test'), 'digest_alg' => 'sha256'], 1);

        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'tls.example.test'], $key);
        $cert = openssl_csr_sign($csr, $ca, $caKey, 30, ['x509_extensions' => 'v3_req', 'config' => $this->opensslConfig('tls.example.test'), 'digest_alg' => 'sha256'], 2);

        openssl_x509_export($ca, $caPem);
        file_put_contents(self::$dir.'/ca.pem', $caPem);

        $result = $this->inspectAgainst($cert, $key, 'tls.example.test', self::$dir.'/ca.pem', $caPem);

        $this->assertSame('valid', $result->status, (string) $result->message);
        $this->assertTrue($result->chainTrusted);
        $this->assertStringContainsString('ServerScope Test CA', (string) $result->issuer);
        $this->assertNotNull($result->protocol);
    }

    // --- helpers ---

    private function selfSigned(string $cn, int $days): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $cn], $key);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['x509_extensions' => 'v3_req', 'config' => $this->opensslConfig($cn), 'digest_alg' => 'sha256'], random_int(1, 1000));

        return [$cert, $key];
    }

    private function opensslConfig(string $san): string
    {
        $path = self::$dir.'/openssl-'.md5($san).'.cnf';
        file_put_contents($path, <<<CNF
            [req]
            distinguished_name = dn
            [dn]
            [v3_req]
            subjectAltName = DNS:{$san}
            [v3_ca]
            basicConstraints = critical, CA:TRUE
            keyUsage = critical, keyCertSign, cRLSign
            CNF);

        return $path;
    }

    private function inspectAgainst($cert, $key, string $host, ?string $caFile = null, string $chainPem = '')
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        $pemFile = self::$dir.'/server-'.uniqid().'.pem';
        file_put_contents($pemFile, $certPem.$chainPem.$keyPem);

        $context = stream_context_create(['ssl' => ['local_cert' => $pemFile, 'verify_peer' => false]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Serve the strict and the lenient handshake, then exit.
            for ($i = 0; $i < 2; $i++) {
                $conn = @stream_socket_accept($server, 5);

                if ($conn) {
                    @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                    @fclose($conn);
                }
            }

            posix_kill(getmypid(), SIGKILL);
        }

        try {
            return (new TlsInspector($caFile))->inspect($host, '127.0.0.1', $port, 5);
        } finally {
            fclose($server);
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}
