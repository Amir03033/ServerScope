<?php

namespace App\Services\Notifications;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Discord incoming webhooks: URL validation, masking and the embed that
 * is sent for a webhook delivery payload.
 *
 * The webhook URL contains the token that allows posting to the channel,
 * so it is treated as a secret: stored encrypted, shown masked and
 * stripped from error messages.
 */
class DiscordMessage
{
    private const URL_PATTERN = '~\Ahttps://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/(?:v\d{1,2}/)?webhooks/(\d{15,25})/([A-Za-z0-9_-]{30,120})/?\z~';

    private const COLORS = [
        'critical' => 0xE5484D,
        'high' => 0xF76B15,
        'warning' => 0xF5A524,
        'info' => 0x3B82F6,
        'resolved' => 0x30A46C,
    ];

    /** Title per incident source for opened and resolved events. */
    private const TITLES = [
        'agent' => ['Server offline', 'Server weer online'],
        'alert' => ['Monitoring-alert geactiveerd', 'Monitoring-alert opgelost'],
        'http' => ['Website onbereikbaar', 'Website weer bereikbaar'],
        'ssl' => ['Probleem met SSL-certificaat', 'SSL-certificaat weer geldig'],
        'ssl_expiry' => ['SSL-certificaat verloopt binnenkort', 'SSL-certificaat vernieuwd'],
        'dns' => ['DNS-resolutie mislukt', 'DNS-resolutie hersteld'],
        'expiry' => ['Domeinregistratie verloopt binnenkort', 'Domeinregistratie in orde'],
        'manual' => ['Incident aangemaakt', 'Incident opgelost'],
    ];

    private const STATUS_LABELS = [
        'open' => 'Open',
        'acknowledged' => 'Bevestigd',
        'investigating' => 'In onderzoek',
        'resolved' => 'Opgelost',
        'closed' => 'Gesloten',
    ];

    public static function isValidUrl(string $url): bool
    {
        return preg_match(self::URL_PATTERN, $url) === 1;
    }

    /**
     * Normalised form that is stored (encrypted) and posted to.
     */
    public static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * Display-only copy without the token.
     */
    public static function mask(string $url): string
    {
        if (preg_match(self::URL_PATTERN, $url, $m) !== 1) {
            return 'https://discord.com/api/webhooks/…';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return "https://{$host}/api/webhooks/{$m[1]}/••••••";
    }

    /**
     * Remove webhook tokens from text that may be stored or shown
     * (transport errors can contain the request URL).
     */
    public static function redact(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('~(webhooks/\d+/)[A-Za-z0-9_-]+~', '$1••••••', $text) ?? '';
    }

    /**
     * @param  array{id?: string, event: string, created_at?: string, data?: array}  $payload
     */
    public static function fromPayload(array $payload): array
    {
        $event = $payload['event'] ?? 'test';
        $data = $payload['data'] ?? [];
        $timestamp = self::timestamp($payload['created_at'] ?? null);

        $embed = match (true) {
            str_starts_with($event, 'incident.') && isset($data['incident']) => self::incidentEmbed($event, $data['incident']),
            $event === 'dns.changed' && isset($data['domain']) => self::dnsEmbed($data['domain'], $data['changes'] ?? []),
            $event === 'page.changed' && isset($data['page']) => [
                'title' => 'Pagina-inhoud gewijzigd',
                'description' => self::clean((string) ($data['page']['url'] ?? ''), 300)."\n".self::clean((string) ($data['change']['summary'] ?? ''), 500),
                'color' => self::COLORS['info'],
            ],
            default => [
                'title' => 'Testmelding',
                'description' => 'De Discord-koppeling met ServerScope werkt. Meldingen voor de geselecteerde gebeurtenissen verschijnen in dit kanaal.',
                'color' => self::COLORS['info'],
            ],
        };

        return [
            'username' => 'ServerScope',
            // Titles contain monitored names; never let them ping anyone.
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                ...$embed,
                'author' => ['name' => 'ServerScope', 'url' => self::appUrl()],
                'footer' => ['text' => 'ServerScope · '.self::eventLabel($event)],
                'timestamp' => $timestamp,
            ]],
        ];
    }

    private static function incidentEmbed(string $event, array $incident): array
    {
        $source = (string) ($incident['source'] ?? 'manual');
        $severity = (string) ($incident['severity'] ?? 'warning');
        $status = (string) ($incident['status'] ?? 'open');
        $titles = self::TITLES[$source] ?? self::TITLES['manual'];
        $subject = $incident['subject']['name'] ?? null;
        $subject = $subject === '—' ? null : $subject;

        $title = match ($event) {
            'incident.resolved' => $titles[1],
            'incident.reopened' => 'Opnieuw: '.lcfirst($titles[0]),
            'incident.escalated' => 'Escalatie: '.lcfirst($titles[0]),
            default => $titles[0],
        };

        $description = '**'.self::clean((string) ($incident['title'] ?? ''), 250).'**';

        if (! empty($incident['description'])) {
            $description .= "\n".self::clean((string) $incident['description'], 1000);
        }

        if ($event === 'incident.resolved' && ! empty($incident['resolution_notes'])) {
            $description .= "\n\n**Oplossing:** ".self::clean((string) $incident['resolution_notes'], 500);
        }

        $fields = [
            ['name' => 'Status', 'value' => self::STATUS_LABELS[$status] ?? $status, 'inline' => true],
            ['name' => 'Ernst', 'value' => \App\Support\Severity::label($severity), 'inline' => true],
            ['name' => 'Bron', 'value' => Incident::SOURCE_LABELS[$source] ?? $source, 'inline' => true],
        ];

        if ($subject !== null) {
            $fields[] = ['name' => self::subjectLabel($incident['subject']['type'] ?? null), 'value' => self::clean($subject, 200), 'inline' => true];
        }

        if (! empty($incident['opened_at'])) {
            $fields[] = ['name' => 'Gestart', 'value' => self::discordTime($incident['opened_at']), 'inline' => true];
        }

        if ($event === 'incident.resolved' && ! empty($incident['resolved_at'])) {
            $fields[] = ['name' => 'Opgelost', 'value' => self::discordTime($incident['resolved_at']), 'inline' => true];
        }

        if (($incident['occurrence_count'] ?? 1) > 1) {
            $fields[] = ['name' => 'Aantal keer', 'value' => (string) $incident['occurrence_count'], 'inline' => true];
        }

        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $event === 'incident.resolved' ? self::COLORS['resolved'] : (self::COLORS[$severity] ?? self::COLORS['warning']),
            'fields' => $fields,
        ];

        if (isset($incident['id']) && ($url = self::route('incidents.show', $incident['id'])) !== null) {
            $embed['url'] = $url;
            $embed['fields'][] = ['name' => 'Incident', 'value' => "[#{$incident['id']} bekijken in ServerScope]({$url})", 'inline' => false];
        }

        return $embed;
    }

    private static function dnsEmbed(array $domain, array $changes): array
    {
        $fields = [];

        foreach (array_slice($changes, 0, 10) as $change) {
            $lines = [
                ...array_map(fn ($v) => '+ '.self::clean((string) $v, 150), array_slice($change['added'] ?? [], 0, 5)),
                ...array_map(fn ($v) => '- '.self::clean((string) $v, 150), array_slice($change['removed'] ?? [], 0, 5)),
            ];

            $fields[] = [
                'name' => (string) ($change['type'] ?? 'DNS'),
                'value' => "```diff\n".mb_substr(implode("\n", $lines) ?: '(geen waarden)', 0, 900)."\n```",
                'inline' => false,
            ];
        }

        $name = self::clean((string) ($domain['display_name'] ?? $domain['name'] ?? ''), 200);

        $embed = [
            'title' => 'DNS-records gewijzigd',
            'description' => "Voor **{$name}** zijn DNS-wijzigingen gedetecteerd. Was dit niet gepland? Controleer dan je DNS-beheer en registrar.",
            'color' => self::COLORS['info'],
            'fields' => $fields,
        ];

        if (isset($domain['id']) && ($url = self::route('domains.show', $domain['id'])) !== null) {
            $embed['url'] = $url;
        }

        return $embed;
    }

    private static function eventLabel(string $event): string
    {
        return match ($event) {
            'incident.opened' => 'Incident geopend',
            'incident.reopened' => 'Incident heropend',
            'incident.resolved' => 'Incident opgelost',
            'incident.escalated' => 'Incident geëscaleerd',
            'dns.changed' => 'DNS gewijzigd',
            'page.changed' => 'Pagina gewijzigd',
            default => 'Testmelding',
        };
    }

    private static function subjectLabel(?string $type): string
    {
        return match ($type) {
            (new Server)->getMorphClass() => 'Server',
            (new Domain)->getMorphClass() => 'Domein',
            default => 'Betreft',
        };
    }

    /**
     * Markdown-safe single value; mentions are disabled separately.
     */
    private static function clean(string $value, int $max): string
    {
        $value = str_replace(['`', '@everyone', '@here'], ["'", "@\u{200B}everyone", "@\u{200B}here"], trim($value));

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    private static function discordTime(string $iso): string
    {
        try {
            $time = CarbonImmutable::parse($iso);
        } catch (Throwable) {
            return $iso;
        }

        // Discord renders <t:…> in the reader's own timezone.
        return "<t:{$time->getTimestamp()}:f> (".Time::format($time, 'd-m-Y H:i T').')';
    }

    private static function timestamp(?string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso ?? 'now')->utc()->toIso8601String();
        } catch (Throwable) {
            return now()->utc()->toIso8601String();
        }
    }

    private static function route(string $name, int|string $id): ?string
    {
        try {
            return route($name, $id);
        } catch (Throwable) {
            return null;
        }
    }

    private static function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/') ?: 'https://serverscope.nl';
    }
}
