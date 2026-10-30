<?php

namespace App\Support;

/**
 * WCAG 2.1 contrast for colours a customer chooses (the accent colour of a
 * public status page): which text colour is readable on it, and whether it
 * is readable as text on the page background.
 */
final class Contrast
{
    /** Surfaces of the design tokens (--ss-surface) in light and dark. */
    public const SURFACE_LIGHT = '#ffffff';

    public const SURFACE_DARK = '#111113';

    /** Minimum for normal text (WCAG AA). */
    public const AA = 4.5;

    public static function ratio(string $a, string $b): float
    {
        [$high, $low] = [self::luminance($a), self::luminance($b)];

        if ($low > $high) {
            [$high, $low] = [$low, $high];
        }

        return ($high + 0.05) / ($low + 0.05);
    }

    /** Black or white text, whichever reads better on the colour. */
    public static function readableOn(string $background): string
    {
        return self::ratio($background, '#000000') >= self::ratio($background, '#ffffff') ? '#000000' : '#ffffff';
    }

    /** The colour itself when it is readable as text on the surface, else null (fall back to the neutral accent). */
    public static function textOn(?string $color, string $surface): ?string
    {
        if (! self::isHex($color)) {
            return null;
        }

        return self::ratio($color, $surface) >= self::AA ? strtolower($color) : null;
    }

    public static function isHex(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(fn (string $pair) => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
        [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
