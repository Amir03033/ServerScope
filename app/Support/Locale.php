<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Interface languages. English is the default; Dutch is a choice per user
 * (users.locale) or per visitor (session). API messages stay as they are.
 */
final class Locale
{
    public const SUPPORTED = ['en', 'nl'];

    public const DEFAULT = 'en';

    public const SESSION_KEY = 'locale';

    /** Native names, shown in the language switch. */
    public const NAMES = ['en' => 'English', 'nl' => 'Nederlands'];

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED, true);
    }

    public static function normalize(mixed $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    /** The first supported language in Accept-Language, or null. */
    public static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(substr($language, 0, 2));

            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }

    /** Cache keys with displayed text carry the language. */
    public static function cacheKey(string $key): string
    {
        return $key.':'.app()->getLocale();
    }
}
