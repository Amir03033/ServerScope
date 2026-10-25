<?php

namespace App\Services\Domains;

use App\Monitoring\Net\TargetGuard;

/**
 * Normalises user input ("https://Bücher.Example/pad", "www.example.com.")
 * to a lowercase ASCII hostname, or returns null if it is not a public
 * domain name.
 */
class DomainNormalizer
{
    private const RESERVED_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp',
        'home.arpa', 'invalid', 'test', 'example', 'onion', 'arpa',
    ];

    public function normalize(string $input): ?string
    {
        $value = trim($input);

        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        } else {
            // Strip path, query and port from bare input like "example.com/pad".
            $value = preg_split('#[/?\#]#', $value)[0];
            $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        }

        $value = rtrim(mb_strtolower($value), '.');

        if ($value === '' || str_contains($value, '@') || filter_var(trim($value, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $ascii = TargetGuard::toAscii($value);

        if ($ascii === null || substr_count($ascii, '.') < 1) {
            return null;
        }

        $tld = substr($ascii, strrpos($ascii, '.') + 1);

        // A TLD is alphabetic or an IDN A-label; this also rejects numeric names.
        if (! preg_match('/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld)) {
            return null;
        }

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if ($ascii === $suffix || str_ends_with($ascii, '.'.$suffix)) {
                return null;
            }
        }

        return $ascii;
    }
}
