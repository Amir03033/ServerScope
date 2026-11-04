<?php

namespace App\Services\Logs;

/**
 * Groups log lines that describe the same problem: timestamps, numbers,
 * ids, hashes, quoted values and addresses are replaced by placeholders
 * before hashing, so "Order 123 failed" and "Order 456 failed" are one
 * issue. Also removes secrets the agent may have missed.
 */
final class LogFingerprint
{
    public static function of(string $source, string $message): string
    {
        return sha1($source."\n".self::pattern($message));
    }

    /** The message with its variable parts replaced; shown as the issue's name. */
    public static function pattern(string $message): string
    {
        $text = mb_substr($message, 0, 500);
        $rules = [
            '/\b\d{4}[-\/]\d{2}[-\/]\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?/' => '<time>',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
            '/\b[0-9a-f]{16,}\b/i' => '<hash>',
            '/\b\d+(?:\.\d+){3}(?::\d+)?\b/' => '<ip>',
            '/"[^"]{0,200}"/' => '"…"',
            "/'[^']{0,200}'/" => "'…'",
            '/\b\d+(?:\.\d+)?\b/' => '<n>',
        ];

        return trim(preg_replace(array_keys($rules), array_values($rules), $text) ?? $text);
    }

    /** Server-side safety net for secrets in log text. */
    public static function redact(string $text): string
    {
        return preg_replace([
            '/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|auth|cookie|session)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
            '/\b(?:[A-Za-z0-9+\/]{40,}={0,2}|[0-9a-f]{40,})\b/',
        ], ['$1$2[redacted]', 'Bearer [redacted]', '[email]', '[redacted]'], $text) ?? $text;
    }
}
