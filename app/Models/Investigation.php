<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deterministic NEXUS Intelligence investigation of an incident
 * (App\Services\Intelligence\InvestigationRunner). The result is an
 * immutable snapshot of what was observed at as_of; hypotheses in it are
 * never confirmed causes (those live in IncidentLearning, set by people).
 */
class Investigation extends Model
{
    use BelongsToTeam;

    public const STATUSES = ['queued', 'running', 'completed', 'partial', 'failed', 'superseded'];

    public const PENDING = ['queued', 'running'];

    /** Runs with a result that can be shown. */
    public const FINISHED = ['completed', 'partial', 'superseded'];

    public const TRIGGERS = ['opened', 'reopened', 'resolved', 'manual'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'scope' => 'array',
            'result' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pipeline_version' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function owningTeamId(): ?int
    {
        return Incident::query()->withoutGlobalScopes()->whereKey($this->incident_id)->value('team_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    public function hasResult(): bool
    {
        return in_array($this->status, self::FINISHED, true) && is_array($this->result);
    }

    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereIn('status', self::FINISHED);
    }
}
