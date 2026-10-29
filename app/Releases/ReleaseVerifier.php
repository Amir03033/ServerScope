<?php

namespace App\Releases;

/**
 * Checks a release exactly like the updater on a customer server does:
 * first the signature over the manifest bytes with a trusted key, then
 * the manifest, then the tarball against the manifest.
 */
class ReleaseVerifier
{
    public function __construct(private readonly ReleaseKeys $keys) {}

    public function verify(string $manifest, string $signature): ReleaseManifest
    {
        $keys = $this->keys->trusted();

        if ($keys === []) {
            throw new InvalidRelease('no_trusted_key');
        }

        $raw = base64_decode(trim($signature), true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || base64_encode($raw) !== trim($signature)) {
            throw new InvalidRelease('bad_signature');
        }

        $signedBy = null;

        foreach ($keys as $keyId => $public) {
            if (sodium_crypto_sign_verify_detached($raw, $manifest, $public)) {
                $signedBy = $keyId;
                break;
            }
        }

        if ($signedBy === null) {
            throw new InvalidRelease('bad_signature');
        }

        $parsed = ReleaseManifest::parse($manifest);

        if (! hash_equals($signedBy, $parsed->keyId)) {
            throw new InvalidRelease('bad_manifest', 'Het manifest noemt een andere sleutel dan waarmee het is ondertekend.');
        }

        return $parsed;
    }

    public function verifyTarball(ReleaseManifest $manifest, string $tarball): void
    {
        if (strlen($tarball) !== $manifest->size || ! hash_equals($manifest->sha256, hash('sha256', $tarball))) {
            throw new InvalidRelease('bad_checksum');
        }

        ReleaseArchive::entries($tarball);
    }
}
