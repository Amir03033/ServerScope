<?php

namespace App\Support;

/**
 * The base URL that install.sh and the dashboard command use. Always
 * APP_URL, never the request's Host header, and HTTPS only: plain http
 * is allowed solely for local testing with an explicit setting, which is
 * ignored in production.
 */
class AgentInstall
{
    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function insecureAllowed(): bool
    {
        return (bool) config('serverscope.enrollment.allow_insecure_url') && ! app()->isProduction();
    }

    /**
     * Null when the URL is fine, otherwise a Dutch explanation. Also used by
     * the install.sh download (out of scope of the interface translation),
     * so it stays Dutch; the screens use urlProblemKey().
     */
    public static function urlProblem(): ?string
    {
        return match (self::urlProblemKey()) {
            'servers.add.url_invalid' => 'APP_URL is geen geldige URL. Stel APP_URL in op het https-adres van ServerScope.',
            'servers.add.url_not_https' => 'APP_URL gebruikt geen https. Servers kunnen alleen via https worden gekoppeld; stel APP_URL in op het https-adres van ServerScope.',
            default => null,
        };
    }

    /** Translation key of the URL problem for the screens, or null. */
    public static function urlProblemKey(): ?string
    {
        $url = self::baseUrl();
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['https', 'http'], true)) {
            return 'servers.add.url_invalid';
        }

        if ($scheme !== 'https' && ! self::insecureAllowed()) {
            return 'servers.add.url_not_https';
        }

        return null;
    }

    public static function command(): string
    {
        $proto = self::insecureAllowed() && str_starts_with(self::baseUrl(), 'http://') ? '' : "--proto '=https' --tlsv1.2 ";

        return 'curl '.$proto.'-fsSL '.self::baseUrl().'/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh';
    }
}
