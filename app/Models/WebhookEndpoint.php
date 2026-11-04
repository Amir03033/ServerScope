<?php

namespace App\Models;

use App\Support\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use \App\Tenancy\BelongsToTeam;

    public const EVENTS = [
        'incident.opened' => 'Incident geopend',
        'incident.reopened' => 'Incident heropend',
        'incident.resolved' => 'Incident opgelost',
        'incident.escalated' => 'Incident geëscaleerd (drempel/ernst/niet bevestigd)',
        'dns.changed' => 'DNS-records gewijzigd',
        'page.changed' => 'Pagina-inhoud gewijzigd (alleen pagina\'s met wijzigingsmeldingen)',
    ];

    public const TYPE_GENERIC = 'generic';

    public const TYPE_DISCORD = 'discord';

    public const TYPE_SLACK = 'slack';

    public const TYPE_TEAMS = 'teams';

    public const TYPE_TELEGRAM = 'telegram';

    public const TYPES = [
        self::TYPE_GENERIC => 'Algemeen (ondertekende JSON)',
        self::TYPE_DISCORD => 'Discord',
        self::TYPE_SLACK => 'Slack',
        self::TYPE_TEAMS => 'Microsoft Teams',
        self::TYPE_TELEGRAM => 'Telegram',
    ];

    /** Channels read by people: the address is the credential, messages are formatted. */
    public const CHAT_TYPES = [self::TYPE_SLACK, self::TYPE_TEAMS, self::TYPE_TELEGRAM, self::TYPE_DISCORD];

    public const SEVERITIES = [Severity::LOW => 'Laag', Severity::MEDIUM => 'Gemiddeld', Severity::HIGH => 'Hoog', Severity::CRITICAL => 'Kritiek'];

    protected $fillable = ['name', 'type', 'url', 'target', 'secret', 'events', 'min_severity', 'is_active', 'created_by'];

    protected $attributes = ['type' => self::TYPE_GENERIC];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribedTo(string $event): bool
    {
        return $event === 'test' || in_array($event, $this->events ?? [], true);
    }

    public function isDiscord(): bool
    {
        return $this->type === self::TYPE_DISCORD;
    }

    public function isChat(): bool
    {
        return in_array($this->type, self::CHAT_TYPES, true);
    }

    /**
     * The URL requests are sent to. For chat channels the real address
     * (which contains the token) lives encrypted in `secret`; for Telegram
     * that is the bot token.
     */
    public function deliveryUrl(): string
    {
        return match (true) {
            $this->type === self::TYPE_TELEGRAM => \App\Services\Notifications\ChatChannel::telegramUrl((string) $this->secret),
            $this->isChat() => (string) $this->secret,
            default => $this->url,
        };
    }

    public function wantsSeverity(?string $severity): bool
    {
        if ($this->min_severity === null || $severity === null) {
            return true;
        }

        return Severity::atLeast($severity, $this->min_severity);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }

    /**
     * HMAC-SHA256 over "{timestamp}.{body}".
     */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
