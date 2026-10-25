<?php

namespace App\Models;

use App\Observers\AlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(AlertObserver::class)]
class Alert extends Model
{
    protected $fillable = [
        'server_id',
        'alert_rule_id',
        'incident_id',
        'metric',
        'dimension',
        'observed_value',
        'threshold',
        'status',
        'triggered_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_value' => 'float',
            'threshold' => 'float',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class)->withTrashed();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
