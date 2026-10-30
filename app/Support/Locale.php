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

    /**
     * Run a callback in a language (and restore the previous one). Used for
     * messages that are built outside a request: queued jobs, logs.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale(self::isSupported($locale) ? $locale : $previous);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Language of a webhook or Discord endpoint: that of the user who created
     * it, otherwise the application default. Endpoints have no reader of their own.
     */
    public static function forEndpoint(\App\Models\WebhookEndpoint $endpoint): string
    {
        $locale = $endpoint->created_by ? \App\Models\User::query()->whereKey($endpoint->created_by)->value('locale') : null;

        return self::isSupported($locale) ? $locale : (string) config('app.locale', self::DEFAULT);
    }

    /** Cache keys with displayed text carry the language. */
    public static function cacheKey(string $key): string
    {
        return $key.':'.app()->getLocale();
    }
}
