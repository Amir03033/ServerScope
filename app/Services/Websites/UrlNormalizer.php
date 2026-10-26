<?php

namespace App\Services\Websites;

use App\Monitoring\Net\SafeHttpClient;
use App\Monitoring\Net\TargetGuard;

/**
 * Canonical form of page URLs so the same page is stored once:
 * lower-case scheme and host (IDN as punycode), no default port, no
 * fragment, dot segments resolved, percent-encoding normalised, session
 * identifiers removed and, unless kept explicitly, no query string (kept
 * query parameters are sorted and stripped of tracking parameters).
 */
final class UrlNormalizer
{
    public const MAX_LENGTH = 2048;

    private const DROPPED_PARAMETERS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'yclid', 'mc_cid', 'mc_eid', '_ga', '_gl',
        'phpsessid', 'sid', 'sessionid', 'session_id', 'jsessionid', 'aspsessionid', 'cfid', 'cftoken',
    ];

    public static function normalize(string $url, ?string $base = null, bool $keepQuery = false): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        if ($base !== null) {
            if (str_starts_with($url, '#')) {
                $url = $base;
            } elseif (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
                $url = SafeHttpClient::resolveUrl($base, $url) ?? '';
            }
        }

        // Fragments never reach the server.
        $url = explode('#', $url, 2)[0];
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = TargetGuard::toAscii(strtolower(rtrim($parts['host'], '.')));

        if ($host === null) {
            return null;
        }

        $port = isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ':'.$parts['port']
            : '';

        $path = self::normalizePath($parts['path'] ?? '/');
        $query = $keepQuery && isset($parts['query']) ? self::normalizeQuery($parts['query']) : '';

        $normalized = $scheme.'://'.$host.$port.$path.($query !== '' ? '?'.$query : '');

        return strlen($normalized) <= self::MAX_LENGTH ? $normalized : null;
    }

    /** The host of a normalised URL. */
    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * URLs that look like crawler traps: very deep paths, a segment that
     * repeats (/a/b/a/b/a/b) or very many query parameters.
     */
    public static function looksLikeTrap(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if (count($segments) > 15 || strlen($url) > 1024) {
            return true;
        }

        foreach (array_count_values($segments) as $count) {
            if ($count > 3) {
                return true;
            }
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return count($query) > 10;
    }

    private static function normalizePath(string $path): string
    {
        // Session ids in the path (";jsessionid=...").
        $path = preg_replace('/;(jsessionid|phpsessid|sid)=[^\/?]*/i', '', $path) ?? $path;
        $path = self::normalizeEncoding($path);

        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }

        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($output);
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        $normalized = implode('/', $output);

        if ($normalized === '' || $normalized[0] !== '/') {
            $normalized = '/'.$normalized;
        }

        // "/a/b/.." resolves to "/a/".
        if (str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            $normalized = rtrim($normalized, '/').'/';
        }

        return $normalized;
    }

    private static function normalizeQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $name = strtolower(urldecode($key));

            if (str_starts_with($name, 'utm_') || in_array($name, self::DROPPED_PARAMETERS, true)) {
                continue;
            }

            $pairs[] = [self::normalizeEncoding($key), $value === null ? null : self::normalizeEncoding($value)];
        }

        usort($pairs, fn ($a, $b) => [$a[0], (string) $a[1]] <=> [$b[0], (string) $b[1]]);

        return implode('&', array_map(fn ($p) => $p[1] === null ? $p[0] : $p[0].'='.$p[1], $pairs));
    }

    /** Decode unreserved characters, upper-case other escapes, encode spaces. */
    private static function normalizeEncoding(string $value): string
    {
        $value = preg_replace_callback('/%([0-9a-fA-F]{2})/', function (array $m): string {
            $char = chr(hexdec($m[1]));

            return preg_match('/[A-Za-z0-9\-._~]/', $char) ? $char : '%'.strtoupper($m[1]);
        }, $value) ?? $value;

        return str_replace(' ', '%20', $value);
    }
}
