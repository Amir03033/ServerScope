<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_LABELS = [
        'opened' => 'Incident geopend',
        'reopened' => 'Incident heropend',
        'occurrence' => 'Opnieuw waargenomen',
        'severity_changed' => 'Ernst gewijzigd',
        'acknowledged' => 'Bevestigd',
        'investigating' => 'Onderzoek gestart',
        'assigned' => 'Toegewezen',
        'comment' => 'Notitie',
        'recovered' => 'Automatisch hersteld',
        'resolved' => 'Opgelost',
        'closed' => 'Gesloten',
        'escalated' => 'Geëscaleerd',
        'notified' => 'Melding verstuurd',
    ];

    protected $fillable = ['incident_id', 'type', 'user_id', 'message', 'data', 'created_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst($this->type);
    }
}
