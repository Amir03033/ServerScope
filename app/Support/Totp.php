<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 30 s, 6 digits),
 * compatible with common authenticator apps.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null, int $period = 30): string
    {
        return self::codeForStep(self::base32Decode($secret), intdiv($timestamp ?? now()->getTimestamp(), $period));
    }

    /**
     * Returns the matching time step (for replay protection) or null.
     * Accepts one step of clock drift either way.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1, int $period = 30): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $key = self::base32Decode($secret);
        $step = intdiv($timestamp ?? now()->getTimestamp(), $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeForStep($key, $step + $offset), $code)) {
                return $step + $offset;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'ServerScope'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private static function codeForStep(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[\s=]/', '', $data) ?? '');
        $bits = '';

        foreach (str_split($data) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
