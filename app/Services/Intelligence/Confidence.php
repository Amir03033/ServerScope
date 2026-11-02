<?php

namespace App\Services\Intelligence;

/**
 * Ordinal confidence levels. No percentages: there is no calibration data
 * that would justify them. "insufficient" is a valid outcome.
 */
final class Confidence
{
    public const LEVELS = ['insufficient' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    public static function rank(string $level): int
    {
        return self::LEVELS[$level] ?? 0;
    }

    /** One level lower (never below insufficient). */
    public static function down(string $level): string
    {
        return array_search(max(0, self::rank($level) - 1), self::LEVELS, true);
    }

    /** At most $max. */
    public static function cap(string $level, string $max): string
    {
        return self::rank($level) > self::rank($max) ? $max : $level;
    }

    public static function atLeast(string $level, string $min): bool
    {
        return self::rank($level) >= self::rank($min);
    }
}
