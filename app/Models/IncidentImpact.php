<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A resource affected by (or causing) an incident. Page-level details of
 * a grouped website incident live here, so grouping never loses them.
 */
class IncidentImpact extends Model
{
    public const ROLES = ['cause', 'affected'];

    protected $fillable = [
        'incident_id', 'resource_type', 'resource_id', 'role', 'status', 'failure_type', 'message',
        'first_failed_at', 'last_failed_at', 'recovered_at', 'details',
    ];

    protected function casts(): array
    {
        return [
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'recovered_at' => 'datetime',
            'details' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFailing(): bool
    {
        return $this->status === 'failing';
    }
}
