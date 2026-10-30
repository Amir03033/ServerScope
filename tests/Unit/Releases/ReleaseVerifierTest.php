<?php

namespace Tests\Unit\Releases;

use App\Releases\InvalidRelease;
use App\Releases\ReleaseArchive;
use App\Releases\ReleaseKeys;
use App\Releases\ReleaseManifest;
use App\Releases\ReleaseVerifier;
use Tests\Support\SignedReleases;
use Tests\TestCase;

class ReleaseVerifierTest extends TestCase
{
    use SignedReleases;

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function reason(callable $callback): string
    {
        try {
            $callback();
        } catch (InvalidRelease $error) {
            return $error->reason;
        }

        $this->fail('Expected InvalidRelease.');
    }

    public function test_a_correctly_signed_manifest_is_accepted(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        $tarball = $this->releaseTarball('0.3.0');
        $manifest = $this->releaseManifest('0.3.0', $tarball, $key['key_id']);

        $verified = app(ReleaseVerifier::class)->verify($manifest, $this->signRelease($manifest, $key));

        $this->assertSame('0.3.0', $verified->version);
        $this->assertSame(hash('sha256', $tarball), $verified->sha256);
        $this->assertSame($key['key_id'], $verified->keyId);
        $this->assertSame(str_repeat('ab', 20), $verified->commit);
        $this->assertSame(1, $verified->minUpdaterVersion);
    }

    public function test_a_signature_of_another_key_is_refused(): void
    {
        $trusted = $this->releaseKey();
        $other = $this->releaseKey();
        $this->trustReleaseKeys($trusted);
        $manifest = $this->releaseManifest('0.3.0', 'x', $other['key_id']);

        $this->assertSame('bad_signature', $this->reason(fn () => app(ReleaseVerifier::class)->verify($manifest, $this->signRelease($manifest, $other))));

        // Even when the manifest claims the trusted key.
        $claim = $this->releaseManifest('0.3.0', 'x', $trusted['key_id']);
        $this->assertSame('bad_signature', $this->reason(fn () => app(ReleaseVerifier::class)->verify($claim, $this->signRelease($claim, $other))));
    }

    public function test_a_changed_manifest_is_refused(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        $manifest = $this->releaseManifest('0.3.0', 'x', $key['key_id']);
        $signature = $this->signRelease($manifest, $key);

        foreach ([str_replace('0.3.0', '0.3.1', $manifest), $manifest."\n", rtrim($manifest, "\n"), str_replace("\n", "\r\n", $manifest)] as $changed) {
            $this->assertSame('bad_signature', $this->reason(fn () => app(ReleaseVerifier::class)->verify($changed, $signature)));
        }
    }

    public function test_a_malformed_signature_is_refused(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        $manifest = $this->releaseManifest('0.3.0', 'x', $key['key_id']);

        foreach (['', 'nietbase64!!', base64_encode(str_repeat("\0", 63)), base64_encode(str_repeat("\0", 64))] as $signature) {
            $this->assertSame('bad_signature', $this->reason(fn () => app(ReleaseVerifier::class)->verify($manifest, $signature)));
        }
    }

    public function test_no_trusted_key_refuses_everything(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys();
        $manifest = $this->releaseManifest('0.3.0', 'x', $key['key_id']);

        $this->assertSame('no_trusted_key', $this->reason(fn () => app(ReleaseVerifier::class)->verify($manifest, $this->signRelease($manifest, $key))));
    }

    public function test_a_signed_manifest_with_a_wrong_key_id_is_refused(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        $manifest = $this->releaseManifest('0.3.0', 'x', str_repeat('0', 16));

        $this->assertSame('bad_manifest', $this->reason(fn () => app(ReleaseVerifier::class)->verify($manifest, $this->signRelease($manifest, $key))));
    }

    public function test_the_manifest_is_parsed_strictly(): void
    {
        $good = $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16));
        $this->assertSame('0.3.0', ReleaseManifest::parse($good)->version);

        $bad = [
            'other product' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['product' => 'other']),
            'other format' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['format' => 'serverscope-agent-release-2']),
            'version' => $this->releaseManifest('0.3', 'x', str_repeat('a', 16)),
            'leading zero' => $this->releaseManifest('0.03.0', 'x', str_repeat('a', 16)),
            'filename' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['filename' => '../evil.tar.gz']),
            'filename of other version' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['filename' => 'serverscope-agent-0.2.0.tar.gz']),
            'sha256' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['sha256' => strtoupper(hash('sha256', 'x'))]),
            'size zero' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['size' => '0']),
            'size too large' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['size' => '999999999']),
            'commit' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['commit' => 'main']),
            'date' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['released_at' => 'gisteren']),
            'updater version' => $this->releaseManifest('0.3.0', 'x', str_repeat('a', 16), ['min_updater_version' => '0']),
            'extra field' => $good."extra=1\n",
            'duplicate field' => $good.'version=9.9.9'."\n",
            'missing newline' => rtrim($good, "\n"),
            'crlf' => str_replace("\n", "\r\n", $good),
            'order' => implode("\n", array_reverse(explode("\n", rtrim($good, "\n"))))."\n",
            'spaces' => str_replace('version=0.3.0', 'version = 0.3.0', $good),
            'empty' => '',
        ];

        foreach ($bad as $label => $manifest) {
            $this->assertSame('bad_manifest', $this->reason(fn () => ReleaseManifest::parse($manifest)), $label);
        }
    }

    public function test_the_tarball_must_match_the_manifest(): void
    {
        $key = $this->releaseKey();
        $tarball = $this->releaseTarball('0.3.0');
        $manifest = ReleaseManifest::parse($this->releaseManifest('0.3.0', $tarball, $key['key_id']));
        $verifier = app(ReleaseVerifier::class);

        $verifier->verifyTarball($manifest, $tarball);

        $changed = $tarball;
        $changed[20] = $changed[20] === 'a' ? 'b' : 'a';
        $this->assertSame('bad_checksum', $this->reason(fn () => $verifier->verifyTarball($manifest, $changed)));
        $this->assertSame('bad_checksum', $this->reason(fn () => $verifier->verifyTarball($manifest, $tarball.'x')));
    }

    public function test_the_archive_may_only_hold_the_expected_regular_files(): void
    {
        $entries = ReleaseArchive::entries($this->releaseTarball('0.3.0'));
        $this->assertContains('serverscope_agent/agent.py', $entries);
        $this->assertContains('updater/serverscope-updater', $entries);

        $bad = [
            'unexpected file' => ['evil.sh' => 'x'],
            'nested' => ['serverscope_agent/sub/x.py' => 'x'],
            'missing agent' => ['serverscope_agent/agent.py' => null],
            'missing updater' => ['updater/serverscope-updater' => null],
            'missing unit' => ['serverscope-agent.service' => null],
        ];

        foreach ($bad as $label => $files) {
            $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries($this->releaseTarball('0.3.0', $files))), $label);
        }

        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries('geen gzip')));
        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries(gzencode($this->tarEntry('serverscope_agent/agent.py', '2', '/etc/shadow')))));
        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries(gzencode($this->tarEntry('/etc/cron.d/x', '0')))));
        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries(gzencode($this->tarEntry('serverscope_agent/../../etc/x.py', '0')))));
        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries(gzencode($this->tarEntry('serverscope_agent/', '3')))));
        $this->assertSame('bad_archive', $this->reason(fn () => ReleaseArchive::entries(gzencode($this->tarEntry('pax', 'x')))));
    }

    public function test_keys_are_read_from_pem_files_and_identified_by_key_id(): void
    {
        $key = $this->releaseKey();
        $dir = $this->trustReleaseKeys($key);
        file_put_contents($dir.'/README.md', 'geen sleutel');

        $keys = app(ReleaseKeys::class)->trusted();

        $this->assertSame([$key['key_id'] => $key['public']], $keys);
        $this->assertSame($key['key_id'], ReleaseKeys::keyId($key['public']));
        $this->assertSame(64, strlen(ReleaseKeys::fingerprint($key['public'])));

        // An RSA or malformed key file is an error, never silently skipped.
        file_put_contents($dir.'/bad.pub', "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n");
        $this->assertSame('bad_key', $this->reason(fn () => app(ReleaseKeys::class)->trusted()));
    }

    /** One raw ustar header with the given type (and link target). */
    private function tarEntry(string $name, string $type, string $link = ''): string
    {
        $header = str_pad($name, 100, "\0").str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
            .str_pad('00000000000', 12, "\0").str_pad('00000000000', 12, "\0").'        '.$type.str_pad($link, 100, "\0")."ustar\0".'00';
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8).str_repeat("\0", 1024);
    }
}
