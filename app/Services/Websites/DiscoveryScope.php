<?php

namespace App\Services\Websites;

use App\Models\Domain;

/**
 * Which URLs belong to a website for discovery: same scheme family
 * (http/https), the website's host (or its subdomains when explicitly
 * enabled), allowed/excluded path patterns and no crawler traps.
 * Patterns are globs on the path and query, e.g. "/docs/*" or "*.pdf".
 */
final class DiscoveryScope
{
    private string $host;

    private string $baseDomain;

    /** @param  array{allowed_patterns: array<int, string>, excluded_patterns: array<int, string>, include_subdomains: bool, keep_query: bool}  $settings */
    public function __construct(Domain $domain, private array $settings)
    {
        $this->host = strtolower($domain->name);
        $this->baseDomain = strtolower($domain->registrable_domain ?: preg_replace('/^www\./', '', $this->host));
    }

    public function normalize(string $url, ?string $base = null): ?string
    {
        return UrlNormalizer::normalize($url, $base, (bool) $this->settings['keep_query']);
    }

    public function hostAllowed(string $host): bool
    {
        $host = strtolower($host);

        if ($host === $this->host) {
            return true;
        }

        return $this->settings['include_subdomains']
            && ($host === $this->baseDomain || str_ends_with($host, '.'.$this->baseDomain));
    }

    /**
     * @return ?string null when allowed, otherwise the reason
     */
    public function rejectionReason(string $normalizedUrl): ?string
    {
        if (! $this->hostAllowed(UrlNormalizer::host($normalizedUrl))) {
            return 'buiten domein';
        }

        if (UrlNormalizer::looksLikeTrap($normalizedUrl)) {
            return 'mogelijke crawlerval';
        }

        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);
        $query = parse_url($normalizedUrl, PHP_URL_QUERY);
        $target = $path.($query ? '?'.$query : '');

        foreach ($this->settings['excluded_patterns'] as $pattern) {
            if (self::matches($pattern, $target)) {
                return 'uitgesloten patroon';
            }
        }

        if ($this->settings['allowed_patterns'] !== []) {
            foreach ($this->settings['allowed_patterns'] as $pattern) {
                if (self::matches($pattern, $target)) {
                    return null;
                }
            }

            return 'niet in toegestane patronen';
        }

        return null;
    }

    public static function matches(string $pattern, string $target): bool
    {
        $pattern = trim($pattern);

        return $pattern !== '' && fnmatch($pattern, $target, FNM_CASEFOLD);
    }
}
