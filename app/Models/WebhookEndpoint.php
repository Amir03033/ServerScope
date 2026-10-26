<?php

namespace App\Models;

use App\Support\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
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

    public const TYPES = [
        self::TYPE_GENERIC => 'Algemeen (ondertekende JSON)',
        self::TYPE_DISCORD => 'Discord',
    ];

    public const SEVERITIES = [Severity::LOW => 'Laag', Severity::MEDIUM => 'Gemiddeld', Severity::HIGH => 'Hoog', Severity::CRITICAL => 'Kritiek'];

    protected $fillable = ['name', 'type', 'url', 'secret', 'events', 'min_severity', 'is_active', 'created_by'];

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

    /**
     * The URL requests are sent to. For Discord the real webhook URL
     * (which contains the token) lives encrypted in `secret`.
     */
    public function deliveryUrl(): string
    {
        return $this->isDiscord() ? (string) $this->secret : $this->url;
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
