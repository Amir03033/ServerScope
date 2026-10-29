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

    /** Null when the URL is fine, otherwise a Dutch explanation. */
    public static function urlProblem(): ?string
    {
        $url = self::baseUrl();
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['https', 'http'], true)) {
            return 'APP_URL is geen geldige URL. Stel APP_URL in op het https-adres van ServerScope.';
        }

        if ($scheme !== 'https' && ! self::insecureAllowed()) {
            return 'APP_URL gebruikt geen https. Servers kunnen alleen via https worden gekoppeld; stel APP_URL in op het https-adres van ServerScope.';
        }

        return null;
    }

    public static function command(): string
    {
        $proto = self::insecureAllowed() && str_starts_with(self::baseUrl(), 'http://') ? '' : "--proto '=https' --tlsv1.2 ";

        return 'curl '.$proto.'-fsSL '.self::baseUrl().'/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh';
    }
}
