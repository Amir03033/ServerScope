<?php

namespace App\Services\Notifications;

use App\Support\Label;
use App\Support\Severity;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * The message for a webhook delivery in Slack, Microsoft Teams or
 * Telegram. One reading of the payload (title, facts, links), three ways
 * to write it. Everything from monitored resources is escaped for the
 * channel; nothing mentions or pings anyone.
 *
 * Buttons are links: "View" opens the incident, "Acknowledge" opens a
 * confirmation page (a link alone never changes anything).
 */
class ChatMessage
{
    private const COLORS = ['critical' => '#E5484D', 'high' => '#F76B15', 'warning' => '#F5A524', 'info' => '#3B82F6', 'resolved' => '#30A46C'];

    private const TEAMS_COLORS = ['critical' => 'Attention', 'high' => 'Attention', 'warning' => 'Warning', 'info' => 'Accent', 'resolved' => 'Good'];

    /**
     * @param  array{event?: string, created_at?: string, data?: array}  $payload
     * @return array{title: string, headline: ?string, text: ?string, tone: string, facts: array<int, array{0: string, 1: string}>, links: array<int, array{0: string, 1: string}>, footer: string}
     */
    public static function content(array $payload): array
    {
        $event = $payload['event'] ?? 'test';
        $data = $payload['data'] ?? [];
        $footer = 'ServerScope · '.self::eventLabel($event);

        if (str_starts_with($event, 'incident.') && isset($data['incident'])) {
            return [...self::incident($event, $data['incident']), 'footer' => $footer];
        }

        if ($event === 'dns.changed' && isset($data['domain'])) {
            $name = (string) ($data['domain']['display_name'] ?? $data['domain']['name'] ?? '');
            $facts = [];

            foreach (array_slice($data['changes'] ?? [], 0, 8) as $change) {
                $lines = [...array_map(fn ($v) => '+ '.$v, array_slice($change['added'] ?? [], 0, 4)), ...array_map(fn ($v) => '- '.$v, array_slice($change['removed'] ?? [], 0, 4))];
                $facts[] = [(string) ($change['type'] ?? 'DNS'), self::cut(implode("\n", $lines) ?: __('messages.discord.no_values'), 600)];
            }

            return [
                'title' => __('messages.discord.dns_title'),
                'headline' => self::cut($name, 200),
                'text' => str_replace('**', '', __('messages.discord.dns_text', ['domain' => $name])),
                'tone' => 'info',
                'facts' => $facts,
                'links' => isset($data['domain']['id']) && ($url = self::route('domains.show', $data['domain']['id'])) ? [[__('messages.chat.view_domain'), $url]] : [],
                'footer' => $footer,
            ];
        }

        if ($event === 'page.changed' && isset($data['page'])) {
            return [
                'title' => __('messages.discord.page_changed'),
                'headline' => self::cut((string) ($data['page']['url'] ?? ''), 300),
                'text' => self::cut((string) ($data['change']['summary'] ?? ''), 500),
                'tone' => 'info',
                'facts' => [],
                'links' => [],
                'footer' => $footer,
            ];
        }

        return ['title' => __('messages.discord.test_title'), 'headline' => null, 'text' => __('messages.chat.test_text'), 'tone' => 'info', 'facts' => [], 'links' => [[__('messages.chat.open'), self::appUrl()]], 'footer' => $footer];
    }

    /** Slack incoming webhook: a coloured attachment with Block Kit sections. */
    public static function slack(array $payload): array
    {
        $c = self::content($payload);
        $esc = fn (string $v) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);

        $blocks = [['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => self::cut($c['title'], 150), 'emoji' => false]]];
        $body = trim(($c['headline'] !== null ? '*'.$esc($c['headline']).'*' : '')."\n".($c['text'] !== null ? $esc($c['text']) : ''));

        if ($body !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::cut($body, 2900)]];
        }

        if ($c['facts'] !== []) {
            $blocks[] = ['type' => 'section', 'fields' => array_map(fn ($f) => ['type' => 'mrkdwn', 'text' => self::cut('*'.$esc($f[0])."*\n".$esc($f[1]), 1900)], array_slice($c['facts'], 0, 10))];
        }

        $links = array_map(fn ($l) => '<'.$l[1].'|'.$esc($l[0]).'>', $c['links']);
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => implode('  ·  ', [...$links, $esc($c['footer'])])]]];

        return [
            'text' => self::cut($c['title'].($c['headline'] ? ': '.$c['headline'] : ''), 300),
            'attachments' => [['color' => self::COLORS[$c['tone']] ?? self::COLORS['info'], 'blocks' => $blocks]],
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];
    }

    /** Microsoft Teams (Workflows webhook): an Adaptive Card with facts and link buttons. */
    public static function teams(array $payload): array
    {
        $c = self::content($payload);
        $body = [
            ['type' => 'TextBlock', 'text' => ($c['title']), 'weight' => 'Bolder', 'size' => 'Medium', 'color' => self::TEAMS_COLORS[$c['tone']] ?? 'Accent', 'wrap' => true],
        ];

        if ($c['headline'] !== null) {
            $body[] = ['type' => 'TextBlock', 'text' => ($c['headline']), 'weight' => 'Bolder', 'wrap' => true, 'spacing' => 'Small'];
        }

        if ($c['text'] !== null && $c['text'] !== '') {
            $body[] = ['type' => 'TextBlock', 'text' => ($c['text']), 'wrap' => true, 'isSubtle' => true, 'spacing' => 'Small'];
        }

        if ($c['facts'] !== []) {
            $body[] = ['type' => 'FactSet', 'facts' => array_map(fn ($f) => ['title' => ($f[0]), 'value' => ($f[1])], $c['facts'])];
        }

        $body[] = ['type' => 'TextBlock', 'text' => ($c['footer']), 'size' => 'Small', 'isSubtle' => true, 'spacing' => 'Medium'];

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                    'actions' => array_map(fn ($l) => ['type' => 'Action.OpenUrl', 'title' => $l[0], 'url' => $l[1]], $c['links']),
                ],
            ]],
        ];
    }

    /** Telegram Bot API sendMessage: HTML text with link buttons under it. */
    public static function telegram(array $payload, string $chat): array
    {
        $c = self::content($payload);
        $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        $icon = ['critical' => '🔴', 'high' => '🟠', 'warning' => '🟡', 'info' => '🔵', 'resolved' => '🟢'][$c['tone']] ?? '🔵';

        $lines = [$icon.' <b>'.$esc($c['title']).'</b>'];

        if ($c['headline'] !== null) {
            $lines[] = '<b>'.$esc($c['headline']).'</b>';
        }

        if ($c['text'] !== null && $c['text'] !== '') {
            $lines[] = $esc($c['text']);
        }

        if ($c['facts'] !== []) {
            $lines[] = '';

            foreach ($c['facts'] as [$label, $value]) {
                $lines[] = str_contains($value, "\n") ? '<b>'.$esc($label).'</b>'."\n<pre>".$esc($value).'</pre>' : '<b>'.$esc($label).':</b> '.$esc($value);
            }
        }

        $message = ['chat_id' => $chat, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        $buttons = array_values(array_filter($c['links'], fn ($l) => self::publicUrl($l[1])));

        // Telegram refuses buttons to local addresses; those links go in the text instead.
        if (count($buttons) === count($c['links']) && $buttons !== []) {
            $message['reply_markup'] = ['inline_keyboard' => [array_map(fn ($l) => ['text' => $l[0], 'url' => $l[1]], $buttons)]];
        } elseif ($c['links'] !== []) {
            $lines[] = '';
            $lines[] = implode(' · ', array_map(fn ($l) => '<a href="'.$esc($l[1]).'">'.$esc($l[0]).'</a>', $c['links']));
        }

        $lines[] = '';
        $lines[] = '<i>'.$esc($c['footer']).'</i>';

        return ['text' => self::cut(implode("\n", $lines), 4000), ...$message];
    }

    /** @return array{title: string, headline: ?string, text: ?string, tone: string, facts: array, links: array} */
    private static function incident(string $event, array $incident): array
    {
        $source = (string) ($incident['source'] ?? 'manual');
        $severity = (string) ($incident['severity'] ?? 'warning');
        $status = (string) ($incident['status'] ?? 'open');
        $titles = Lang::has('messages.discord.titles.'.$source) ? __('messages.discord.titles.'.$source) : __('messages.discord.titles.manual');
        $resolved = $event === 'incident.resolved';

        $title = match ($event) {
            'incident.resolved' => $titles[1],
            'incident.reopened' => __('messages.discord.again', ['title' => lcfirst($titles[0])]),
            'incident.escalated' => __('messages.discord.escalation', ['title' => lcfirst($titles[0])]),
            default => $titles[0],
        };

        $text = ! empty($incident['description']) ? self::cut((string) $incident['description'], 800) : null;

        if ($resolved && ! empty($incident['resolution_notes'])) {
            $text = __('messages.discord.resolution').' '.self::cut((string) $incident['resolution_notes'], 500);
        }

        $facts = [
            [__('messages.discord.field_status'), Lang::has('messages.discord.status.'.$status) ? __('messages.discord.status.'.$status) : $status],
            [__('messages.discord.field_severity'), Label::of('severity', $severity, Severity::LABELS)],
        ];

        $subject = $incident['subject']['name'] ?? null;

        if ($subject !== null && $subject !== '—') {
            $facts[] = [__('messages.discord.field_subject'), self::cut((string) $subject, 200)];
        }

        if (! empty($incident['opened_at'])) {
            $facts[] = [__('messages.discord.field_started'), self::time($incident['opened_at'])];
        }

        if ($resolved && ! empty($incident['resolved_at'])) {
            $facts[] = [__('messages.discord.field_resolved'), self::time($incident['resolved_at'])];
        }

        if (($incident['occurrence_count'] ?? 1) > 1) {
            $facts[] = [__('messages.discord.field_count'), (string) $incident['occurrence_count']];
        }

        $links = [];

        if (isset($incident['id']) && ($url = self::route('incidents.show', $incident['id'])) !== null) {
            $links[] = [__('messages.chat.view_incident', ['id' => $incident['id']]), $url];

            if (! $resolved && $status === 'open' && ($ack = self::route('incidents.acknowledge', $incident['id'])) !== null) {
                $links[] = [__('messages.chat.acknowledge'), $ack];
            }
        }

        return [
            'title' => $title,
            'headline' => self::cut((string) ($incident['title'] ?? ''), 250),
            'text' => $text,
            'tone' => $resolved ? 'resolved' : (in_array($severity, ['critical', 'high', 'warning'], true) ? $severity : 'info'),
            'facts' => $facts,
            'links' => $links,
        ];
    }

    private static function eventLabel(string $event): string
    {
        $key = 'messages.discord.events.'.str_replace('.', '_', $event);

        return Lang::has($key) ? __($key) : __('messages.discord.events.test');
    }

    private static function time(string $iso): string
    {
        try {
            return Time::dateTimeZone(CarbonImmutable::parse($iso));
        } catch (Throwable) {
            return $iso;
        }
    }

    private static function cut(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    private static function publicUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://') && $host !== '' && $host !== 'localhost'
            && ! str_ends_with($host, '.localhost') && ! str_ends_with($host, '.test') && filter_var($host, FILTER_VALIDATE_IP) === false;
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
