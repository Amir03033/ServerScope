<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Timestamps are stored in the application timezone (UTC). The interface
 * shows and accepts them in the configured display timezone.
 */
class Time
{
    public static function zone(): string
    {
        return (string) config('serverscope.display_timezone', config('app.timezone'));
    }

    public static function format(?CarbonInterface $value, string $format = 'd-m-Y H:i'): string
    {
        return $value === null ? '—' : $value->copy()->setTimezone(self::zone())->format($format);
    }

    /** Value for an <input type="datetime-local">. */
    public static function input(?CarbonInterface $value): string
    {
        return $value === null ? '' : $value->copy()->setTimezone(self::zone())->format('Y-m-d\TH:i');
    }

    /** Parse user input in the display timezone and return it in UTC. */
    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::zone())->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** "3 minutes ago" / "3 minuten geleden", in the active language. */
    public static function relative(?CarbonInterface $value): string
    {
        return $value === null ? __('common.never') : $value->locale(app()->getLocale())->diffForHumans();
    }

    /** Date in the notation of the active language (nl: 30-10-2026, en: 30 Oct 2026). */
    public static function date(?CarbonInterface $value): string
    {
        return self::localized($value, ['nl' => 'd-m-Y', 'en' => 'j M Y']);
    }

    /** Date and time in the notation of the active language. */
    public static function dateTime(?CarbonInterface $value): string
    {
        return self::localized($value, ['nl' => 'd-m-Y H:i', 'en' => 'j M Y, H:i']);
    }

    /** Date and time with the timezone, for e-mails. */
    public static function dateTimeZone(?CarbonInterface $value): string
    {
        return self::localized($value, ['nl' => 'd-m-Y H:i T', 'en' => 'j M Y, H:i T']);
    }

    /** Date and time with seconds, for check logs. */
    public static function dateTimeSeconds(?CarbonInterface $value): string
    {
        return self::localized($value, ['nl' => 'd-m-Y H:i:s', 'en' => 'j M Y, H:i:s']);
    }

    /** @param  array<string, string>  $formats */
    private static function localized(?CarbonInterface $value, array $formats): string
    {
        if ($value === null) {
            return '—';
        }

        $locale = app()->getLocale();

        return $value->copy()->setTimezone(self::zone())->locale($locale)->translatedFormat($formats[$locale] ?? $formats['en']);
    }
}
