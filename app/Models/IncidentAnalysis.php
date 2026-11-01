<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI analysis of an incident (App\Services\Incidents\IncidentInvestigator).
 * Its content is AI-generated and never a confirmed cause; confirmed
 * causes live in IncidentLearning and are only set by people.
 */
class IncidentAnalysis extends Model
{
    use BelongsToTeam;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'result' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function owningTeamId(): ?int
    {
        return Incident::query()->withoutGlobalScopes()->whereKey($this->incident_id)->value('team_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
