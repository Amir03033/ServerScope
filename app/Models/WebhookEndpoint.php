<?php

namespace App\Models;

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
    ];

    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'created_by'];

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
