<?php

namespace App\Support;

/**
 * The single severity scale for incidents, alert rules, pages, webhooks
 * and notification preferences.
 *
 * Stored values are kept backwards compatible: "info", "warning" and
 * "critical" existed before; "high" sits between warning and critical.
 * The interface presents them as Low, Medium, High and Critical.
 */
final class Severity
{
    public const LOW = 'info';

    public const MEDIUM = 'warning';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    /** Ascending order. */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    public const RANK = [self::LOW => 0, self::MEDIUM => 1, self::HIGH => 2, self::CRITICAL => 3];

    public const LABELS = [
        self::CRITICAL => 'Kritiek',
        self::HIGH => 'Hoog',
        self::MEDIUM => 'Gemiddeld',
        self::LOW => 'Laag',
    ];

    public static function isValid(?string $severity): bool
    {
        return $severity !== null && isset(self::RANK[$severity]);
    }

    public static function rank(?string $severity): int
    {
        return self::RANK[$severity] ?? 0;
    }

    public static function label(?string $severity): string
    {
        return self::LABELS[$severity] ?? ucfirst((string) $severity);
    }

    /** Whether $severity is at least $minimum. */
    public static function atLeast(?string $severity, ?string $minimum): bool
    {
        return self::rank($severity) >= self::rank($minimum);
    }

    public static function max(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }

    /** Options for a select, most severe first. */
    public static function options(): array
    {
        return self::LABELS;
    }
}
