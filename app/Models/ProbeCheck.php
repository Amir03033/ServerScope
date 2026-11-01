<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A confirmation job for a failing website and the probe's result. */
class ProbeCheck extends Model
{
    use BelongsToTeam;

    public const OUTCOMES = ['confirmed', 'main_only', 'probe_only', 'not_reproduced', 'unavailable'];

    protected $guarded = ['id', 'team_id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'expires_at' => 'datetime', 'claimed_at' => 'datetime', 'checked_at' => 'datetime',
            'success' => 'boolean', 'response_time_ms' => 'float',
        ];
    }

    public function owningTeamId(): ?int
    {
        return Domain::query()->withoutGlobalScopes()->whereKey($this->domain_id)->value('team_id');
    }

    public function probe(): BelongsTo
    {
        return $this->belongsTo(Probe::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
