<?php

namespace App\Services\Websites;

use DOMDocument;
use DOMXPath;

/**
 * Parses XML sitemaps and sitemap indexes (sitemaps.org protocol),
 * optionally gzip-compressed.
 *
 * Safe by construction: documents with a DOCTYPE are refused (no entity
 * expansion or external entities), the network is never accessed while
 * parsing, decompressed size is capped and at most $maxEntries locations
 * are returned. Malformed input yields an error instead of an exception.
 */
final class SitemapParser
{
    public const MAX_DECOMPRESSED_BYTES = 10_485_760;

    /**
     * @return array{type: string, locations: array<int, string>, error: ?string, truncated: bool}
     */
    public static function parse(string $body, int $maxEntries = 50_000): array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body, self::MAX_DECOMPRESSED_BYTES);

            if ($decoded === false) {
                return self::error('Gecomprimeerde sitemap kon niet worden uitgepakt of is te groot.');
            }

            $body = $decoded;
        }

        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        if ($body === '' || ! str_starts_with($body, '<')) {
            return self::error('Geen XML-sitemap (lege of niet-XML-inhoud).');
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            return self::error('Sitemap geweigerd: DOCTYPE- of ENTITY-declaraties zijn niet toegestaan.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            return self::error('Ongeldige XML in sitemap.');
        }

        $root = strtolower($document->documentElement->localName);
        $type = match ($root) {
            'urlset' => 'urlset',
            'sitemapindex' => 'sitemapindex',
            default => null,
        };

        if ($type === null) {
            return self::error("Onbekend sitemaptype <{$root}>.");
        }

        $xpath = new DOMXPath($document);
        $child = $type === 'urlset' ? 'url' : 'sitemap';
        $nodes = $xpath->query("/*[local-name()='{$root}']/*[local-name()='{$child}']/*[local-name()='loc']");
        $locations = [];
        $truncated = false;

        foreach ($nodes ?: [] as $node) {
            if (count($locations) >= $maxEntries) {
                $truncated = true;
                break;
            }

            $location = trim($node->textContent);

            if ($location !== '') {
                $locations[] = $location;
            }
        }

        return ['type' => $type, 'locations' => array_values(array_unique($locations)), 'error' => null, 'truncated' => $truncated];
    }

    private static function error(string $message): array
    {
        return ['type' => 'invalid', 'locations' => [], 'error' => $message, 'truncated' => false];
    }
}
