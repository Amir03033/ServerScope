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

    public static function relative(?CarbonInterface $value): string
    {
        return $value === null ? 'nooit' : $value->locale('nl')->diffForHumans();
    }
}
