<?php

namespace App\Services\Websites;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extracts the meaningful text of a page for change detection.
 *
 * - Only text is compared, never markup or attributes, so rotating
 *   nonces, CSRF tokens, asset hashes and inline scripts do not count.
 * - Scripts, styles, forms' hidden fields, iframes and SVG are removed.
 * - An optional selector limits the comparison to part of the page;
 *   exclusion selectors remove dynamic blocks (ads, "latest posts").
 * - Dates, times, long numbers and token-like strings are masked, so
 *   timestamps and counters do not count as changes by default.
 *
 * Selectors support: tag, #id, .class, tag.class, tag#id and
 * [attribute=value]; one per entry.
 */
class ContentFingerprint
{
    public const SNAPSHOT_LIMIT = 200_000;

    private const REMOVED_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'canvas', 'head'];

    private const BLOCK_TAGS = ['p', 'div', 'li', 'tr', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'table', 'ul', 'ol', 'dd', 'dt', 'blockquote', 'pre', 'form', 'td', 'th'];

    /**
     * @param  array<int, string>  $exclusions
     * @return array{text: string, fingerprint: string, selector_found: bool}
     */
    public static function extract(string $body, ?string $contentType = null, ?string $selector = null, array $exclusions = []): array
    {
        $isHtml = $contentType === null || str_contains(strtolower($contentType), 'html') || preg_match('/<\s*(html|body|div|p)\b/i', $body);

        if (! $isHtml) {
            $text = self::mask(self::lines($body));

            return ['text' => $text, 'fingerprint' => hash('sha256', $text), 'selector_found' => true];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch external resources while parsing.
        $document->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        foreach (self::REMOVED_TAGS as $tag) {
            self::removeAll($xpath->query('//'.$tag));
        }

        self::removeAll($xpath->query('//input[@type="hidden"]'));

        foreach ($exclusions as $exclusion) {
            if (($query = self::toXPath($exclusion)) !== null) {
                self::removeAll($xpath->query($query));
            }
        }

        $root = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        $found = true;

        if ($selector !== null && trim($selector) !== '') {
            $query = self::toXPath($selector);
            $match = $query !== null ? $xpath->query($query)?->item(0) : null;
            $found = $match !== null;
            $root = $match ?? $root;
        }

        $raw = $root !== null ? self::text($root) : '';
        $text = self::mask(self::lines($raw));

        return ['text' => $text, 'fingerprint' => hash('sha256', $text), 'selector_found' => $found];
    }

    /**
     * Line-based difference between two extracted texts.
     *
     * @return array{added: array<int, string>, removed: array<int, string>, ratio: float}
     */
    public static function diff(string $old, string $new, int $maxLines = 20): array
    {
        $oldLines = $old === '' ? [] : explode("\n", $old);
        $newLines = $new === '' ? [] : explode("\n", $new);
        $oldCounts = array_count_values($oldLines);
        $newCounts = array_count_values($newLines);

        $added = [];
        $removed = [];

        foreach ($newCounts as $line => $count) {
            for ($i = 0; $i < $count - ($oldCounts[$line] ?? 0); $i++) {
                $added[] = (string) $line;
            }
        }

        foreach ($oldCounts as $line => $count) {
            for ($i = 0; $i < $count - ($newCounts[$line] ?? 0); $i++) {
                $removed[] = (string) $line;
            }
        }

        $changed = array_sum(array_map('mb_strlen', [...$added, ...$removed]));
        $total = max(1, mb_strlen($old) + mb_strlen($new));

        return [
            'added' => array_map(fn ($l) => mb_substr($l, 0, 300), array_slice($added, 0, $maxLines)),
            'removed' => array_map(fn ($l) => mb_substr($l, 0, 300), array_slice($removed, 0, $maxLines)),
            'ratio' => round(min(1, $changed / $total), 4),
        ];
    }

    /** Translate a simple CSS selector into XPath, or null when unsupported. */
    public static function toXPath(string $selector): ?string
    {
        $selector = trim($selector);

        if (preg_match('/^\[([a-zA-Z_:][-a-zA-Z0-9_:.]*)=["\']?([^"\'\]]*)["\']?\]$/', $selector, $m)) {
            return "//*[@{$m[1]}=".self::literal($m[2]).']';
        }

        if (! preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)?(?:#([-_a-zA-Z0-9]+))?(?:\.([-_a-zA-Z0-9]+))?$/', $selector, $m) || $selector === '') {
            return null;
        }

        $tag = ($m[1] ?? '') !== '' ? strtolower($m[1]) : '*';
        $conditions = [];

        if (($m[2] ?? '') !== '') {
            $conditions[] = '@id='.self::literal($m[2]);
        }

        if (($m[3] ?? '') !== '') {
            $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ".self::literal(' '.$m[3].' ').')';
        }

        return '//'.$tag.($conditions ? '['.implode(' and ', $conditions).']' : '');
    }

    public static function isValidSelector(string $selector): bool
    {
        return self::toXPath($selector) !== null;
    }

    private static function literal(string $value): string
    {
        return str_contains($value, "'") ? '"'.str_replace('"', '', $value).'"' : "'{$value}'";
    }

    private static function removeAll($nodes): void
    {
        if ($nodes === false || $nodes === null) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private static function text(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $node->nodeValue ?? '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::text($child);
        }

        if ($node instanceof DOMElement && in_array(strtolower($node->tagName), self::BLOCK_TAGS, true)) {
            $text = "\n".$text."\n";
        }

        return $text;
    }

    private static function lines(string $text): string
    {
        $lines = array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l) ?? $l), preg_split('/\R/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: []);

        return mb_substr(implode("\n", array_filter($lines, fn ($l) => $l !== '')), 0, self::SNAPSHOT_LIMIT);
    }

    /** Mask values that change on every request without meaning anything. */
    private static function mask(string $text): string
    {
        $patterns = [
            // ISO and European dates, optionally with a time.
            '/\b\d{4}-\d{2}-\d{2}(?:[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\b/' => '{date}',
            '/\b\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}\b/' => '{date}',
            '/\b\d{1,2}:\d{2}(?::\d{2})?\s?(?:am|pm|AM|PM)?\b/' => '{time}',
            // UUIDs, hex and base64-like tokens.
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{token}',
            '/\b[0-9a-f]{16,}\b/i' => '{token}',
            '/\b(?=[A-Za-z0-9+\/_-]*\d)(?=[A-Za-z0-9+\/_-]*[A-Za-z])[A-Za-z0-9+\/_-]{24,}={0,2}/' => '{token}',
            // Long numbers (counters, Unix timestamps, ids).
            '/\b\d{7,}\b/' => '{number}',
            // Relative times.
            '/\b\d+\s+(?:seconds?|minutes?|hours?|days?|seconde[n]?|minuten|minuut|uur|dagen|dag)\s+(?:ago|geleden)\b/i' => '{relative-time}',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }
}
