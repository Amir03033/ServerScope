<?php

namespace App\Support;

/**
 * Numbers in the notation of the active language
 * (nl: 1.234,5 · en: 1,234.5). Missing values show as a dash.
 */
final class Format
{
    private const SEPARATORS = ['nl' => [',', '.'], 'en' => ['.', ',']];

    public static function number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }

        [$decimal, $thousands] = self::SEPARATORS[app()->getLocale()] ?? self::SEPARATORS['en'];

        return number_format((float) $value, $decimals, $decimal, $thousands);
    }

    public static function percent(int|float|string|null $value, int $decimals = 1): string
    {
        $number = self::number($value, $decimals);

        return $number === '—' ? $number : $number.'%';
    }
}
