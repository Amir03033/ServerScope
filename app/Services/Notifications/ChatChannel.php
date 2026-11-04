<?php

namespace App\Services\Notifications;

use App\Models\WebhookEndpoint;

/**
 * Slack, Microsoft Teams and Telegram: which addresses are accepted, how
 * they are shown, and how their credentials are kept out of logs.
 *
 * Like Discord, the address itself is the credential (Slack and Teams
 * webhook URLs, the Telegram bot token): stored encrypted in `secret`,
 * shown masked in `url`, removed from any error that is stored.
 */
class ChatChannel
{
    private const SLACK = '~\Ahttps://hooks\.slack\.com/(services|triggers|workflows)/([A-Za-z0-9_/-]{20,200})\z~';

    // Teams "Workflows" webhooks (Power Automate) and the older connector URLs.
    private const TEAMS_HOSTS = '~(\.logic\.azure\.com|\.api\.powerplatform\.com|\.webhook\.office\.com)\z~';

    private const TELEGRAM_TOKEN = '~\A\d{5,15}:[A-Za-z0-9_-]{30,60}\z~';

    private const TELEGRAM_CHAT = '~\A(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})\z~';

    public static function isValidUrl(string $type, string $url): bool
    {
        return match ($type) {
            WebhookEndpoint::TYPE_DISCORD => DiscordMessage::isValidUrl($url),
            WebhookEndpoint::TYPE_SLACK => preg_match(self::SLACK, $url) === 1,
            WebhookEndpoint::TYPE_TEAMS => self::isTeamsUrl($url),
            default => false,
        };
    }

    public static function isValidTelegramToken(string $token): bool
    {
        return preg_match(self::TELEGRAM_TOKEN, $token) === 1;
    }

    public static function isValidTelegramChat(string $chat): bool
    {
        return preg_match(self::TELEGRAM_CHAT, $chat) === 1;
    }

    public static function telegramUrl(string $token): string
    {
        return 'https://api.telegram.org/bot'.$token.'/sendMessage';
    }

    /** Display-only copy without the credential. */
    public static function mask(string $type, string $value, ?string $target = null): string
    {
        return match ($type) {
            WebhookEndpoint::TYPE_DISCORD => DiscordMessage::mask($value),
            WebhookEndpoint::TYPE_SLACK => preg_match(self::SLACK, $value, $m) === 1 ? 'https://hooks.slack.com/'.$m[1].'/'.explode('/', $m[2])[0].'/••••••' : 'https://hooks.slack.com/…',
            WebhookEndpoint::TYPE_TEAMS => 'https://'.parse_url($value, PHP_URL_HOST).'/…••••••',
            WebhookEndpoint::TYPE_TELEGRAM => 'Telegram · '.$target,
            default => $value,
        };
    }

    /** Removes webhook tokens, signatures and bot tokens from text that may be stored or shown. */
    public static function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = (string) DiscordMessage::redact($text);
        $text = preg_replace('~(hooks\.slack\.com/(?:services|triggers|workflows)/[A-Za-z0-9]+/)[A-Za-z0-9_/-]+~', '$1••••••', $text) ?? '';
        $text = preg_replace('~([?&](?:sig|sp|sv|code)=)[^&\s"\']+~i', '$1••••••', $text) ?? '';

        return preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot••••••', $text) ?? '';
    }

    private static function isTeamsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['port'] ?? 443, [443], true)
            && isset($parts['host'], $parts['path'])
            && preg_match(self::TEAMS_HOSTS, strtolower($parts['host'])) === 1
            && strlen($url) <= 2048;
    }
}
