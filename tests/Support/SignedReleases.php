<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use PharData;

/**
 * Signed agent releases for tests. Every key pair is generated during the
 * test (sodium); no real release key is ever used. The bytes follow the
 * format of scripts/release/build.sh.
 */
trait SignedReleases
{
    /** @var array<int, string> */
    private array $releaseDirs = [];

    /**
     * A fresh Ed25519 key pair.
     *
     * @return array{secret: string, public: string, pem: string, key_id: string}
     */
    protected function releaseKey(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        // SubjectPublicKeyInfo for Ed25519 (RFC 8410): fixed prefix + 32 bytes.
        $der = hex2bin('302a300506032b6570032100').$public;

        return [
            'secret' => sodium_crypto_sign_secretkey($pair),
            'public' => $public,
            'pem' => "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n",
            'key_id' => substr(hash('sha256', $der), 0, 16),
        ];
    }

    /** Trust exactly these keys (a directory of *.pub files). */
    protected function trustReleaseKeys(array ...$keys): string
    {
        $dir = $this->releaseTempDir('keys');

        foreach ($keys as $key) {
            file_put_contents($dir.'/'.$key['key_id'].'.pub', $key['pem']);
        }

        config(['serverscope.releases.key_path' => $dir]);

        return $dir;
    }

    /**
     * The release tarball (tar.gz) in the installed layout.
     *
     * @param  array<string, string|null>  $files  extra or replaced entries (null removes one)
     */
    protected function releaseTarball(string $version, array $files = []): string
    {
        $entries = [
            'requirements.txt' => "psutil\nrequests\npython-dotenv\n",
            'serverscope-agent.service' => (string) file_get_contents(base_path('agent/serverscope-agent.service')),
            'serverscope_agent/__init__.py' => '',
            'serverscope_agent/agent.py' => "# agent\n",
            'serverscope_agent/collector.py' => "AGENT_VERSION = \"{$version}\"\n",
            'updater/serverscope-updater' => "#!/usr/bin/env bash\n",
            'updater/serverscope-updater.service' => "[Service]\n",
            'updater/serverscope-updater.timer' => "[Timer]\n",
        ];

        foreach ($files as $name => $content) {
            if ($content === null) {
                unset($entries[$name]);
            } else {
                $entries[$name] = $content;
            }
        }

        $path = $this->releaseTempDir('tar').'/release.tar';
        $tar = new PharData($path);

        foreach ($entries as $name => $content) {
            $tar->addFromString($name, $content);
        }

        $tar->compress(\Phar::GZ);
        unset($tar);
        $bytes = (string) file_get_contents($path.'.gz');
        File::deleteDirectory(dirname($path));

        return $bytes;
    }

    /** @param  array<string, string>  $overrides */
    protected function releaseManifest(string $version, string $tarball, string $keyId, array $overrides = []): string
    {
        $fields = array_merge([
            'format' => 'serverscope-agent-release-1',
            'product' => 'serverscope-agent',
            'version' => $version,
            'filename' => "serverscope-agent-{$version}.tar.gz",
            'sha256' => hash('sha256', $tarball),
            'size' => (string) strlen($tarball),
            'commit' => str_repeat('ab', 20),
            'released_at' => '2026-11-02T10:00:00Z',
            'min_updater_version' => '1',
            'key_id' => $keyId,
        ], $overrides);

        $manifest = '';

        foreach ($fields as $name => $value) {
            $manifest .= "{$name}={$value}\n";
        }

        return $manifest;
    }

    protected function signRelease(string $manifest, array $key): string
    {
        return base64_encode(sodium_crypto_sign_detached($manifest, $key['secret']))."\n";
    }

    /**
     * A complete bundle as build.sh makes it.
     *
     * @param  array{manifest?: string, signature?: string, tarball?: string, extra?: array<string, string>}  $tamper
     */
    protected function releaseBundle(string $version, array $key, array $tamper = []): string
    {
        $tarball = $this->releaseTarball($version);
        $manifest = $tamper['manifest'] ?? $this->releaseManifest($version, $tarball, $key['key_id']);
        $signature = $tamper['signature'] ?? $this->signRelease($manifest, $key);

        $path = $this->releaseTempDir('bundle')."/serverscope-agent-{$version}.bundle.tar";
        $bundle = new PharData($path);
        $bundle->addFromString('manifest', $manifest);
        $bundle->addFromString('manifest.sig', $signature);
        $bundle->addFromString("serverscope-agent-{$version}.tar.gz", $tamper['tarball'] ?? $tarball);

        foreach ($tamper['extra'] ?? [] as $name => $content) {
            $bundle->addFromString($name, $content);
        }

        unset($bundle);

        return $path;
    }

    protected function releaseTempDir(string $label): string
    {
        $dir = sys_get_temp_dir().'/serverscope-release-test-'.$label.'-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($dir, 0700);
        $this->releaseDirs[] = $dir;

        return $dir;
    }

    protected function cleanReleaseDirs(): void
    {
        foreach ($this->releaseDirs as $dir) {
            File::deleteDirectory($dir);
        }

        $this->releaseDirs = [];
    }
}
