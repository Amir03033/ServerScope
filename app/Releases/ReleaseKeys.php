<?php

namespace App\Releases;

/**
 * The public release keys ServerScope trusts: every *.pub file (PEM,
 * Ed25519) in serverscope.releases.key_path. Several keys may be trusted
 * at once (key rotation); a release names its key by key_id. The private
 * key never exists on the server.
 */
class ReleaseKeys
{
    /** DER prefix of an Ed25519 SubjectPublicKeyInfo (RFC 8410). */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    /** @return array<string, string> key_id => raw 32-byte public key */
    public function trusted(): array
    {
        $directory = (string) config('serverscope.releases.key_path');
        $keys = [];

        foreach (glob(rtrim($directory, '/').'/*.pub') ?: [] as $file) {
            $public = self::fromPem((string) file_get_contents($file));
            $keys[self::keyId($public)] = $public;
        }

        ksort($keys);

        return $keys;
    }

    /** The raw public key from a PEM "PUBLIC KEY" block. */
    public static function fromPem(string $pem): string
    {
        if (preg_match('/\A-----BEGIN PUBLIC KEY-----\r?\n([A-Za-z0-9+\/=\r\n]+)-----END PUBLIC KEY-----\s*\z/', $pem, $match) !== 1) {
            throw new InvalidRelease('bad_key');
        }

        $der = base64_decode(preg_replace('/\s+/', '', $match[1]), true);

        if ($der === false || strlen($der) !== 44 || bin2hex(substr($der, 0, 12)) !== self::SPKI_PREFIX) {
            throw new InvalidRelease('bad_key');
        }

        return substr($der, 12);
    }

    public static function toPem(string $public): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$public), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** SHA-256 of the DER public key, as `openssl pkey -pubin -outform DER | sha256sum` prints it. */
    public static function fingerprint(string $public): string
    {
        return hash('sha256', hex2bin(self::SPKI_PREFIX).$public);
    }

    public static function keyId(string $public): string
    {
        return substr(self::fingerprint($public), 0, 16);
    }
}
