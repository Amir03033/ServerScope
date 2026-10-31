<?php

namespace App\Support;

use App\Models\User;

/**
 * Acceptance of the terms and privacy statement at sign-up. Only the
 * mechanism lives here: the checkbox appears once both URLs are
 * configured (SERVERSCOPE_TERMS_URL, SERVERSCOPE_PRIVACY_URL); without
 * them sign-up behaves as before. No legal text is shipped.
 */
class Legal
{
    public static function termsUrl(): ?string
    {
        return self::safe(config('serverscope.legal.terms_url'));
    }

    public static function privacyUrl(): ?string
    {
        return self::safe(config('serverscope.legal.privacy_url'));
    }

    public static function required(): bool
    {
        return self::termsUrl() !== null && self::privacyUrl() !== null;
    }

    public static function version(): string
    {
        return (string) config('serverscope.legal.version', '1');
    }

    /** Validation rules to merge into a sign-up form. */
    public static function rules(): array
    {
        return self::required() ? ['terms' => ['accepted']] : [];
    }

    public static function stamp(User $user): void
    {
        if (self::required()) {
            $user->terms_accepted_at = now();
            $user->terms_version = self::version();
        }
    }

    /**
     * Only http(s) URLs are ever linked (never javascript: or similar).
     * "{locale}" in the URL becomes the interface language (nl or en), so one
     * setting can point at the page in the right language.
     */
    private static function safe(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';
        $url = str_replace('{locale}', app()->getLocale() === 'nl' ? 'nl' : 'en', $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }
}
