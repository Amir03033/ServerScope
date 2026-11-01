<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident Memory: what was learned from one incident. A cause is a
 * hypothesis until someone marks it confirmed; the outcome says whether
 * the recorded fix worked. Written only through IncidentMemory::save().
 */
class IncidentLearning extends Model
{
    use BelongsToTeam;

    public const CATEGORIES = ['deployment', 'configuration', 'capacity', 'dependency', 'network', 'dns', 'certificate', 'hardware', 'software_bug', 'external_provider', 'human_error', 'unknown'];

    public const CAUSE_STATUSES = ['hypothesis', 'confirmed'];

    public const OUTCOMES = ['successful', 'unsuccessful', 'unresolved'];

    public const MAX_SYMPTOMS = 10;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['symptoms' => 'array', 'version' => 'integer', 'time_to_resolve_minutes' => 'integer'];
    }

    public function owningTeamId(): ?int
    {
        return Incident::query()->withoutGlobalScopes()->whereKey($this->incident_id)->value('team_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isConfirmed(): bool
    {
        return $this->cause_status === 'confirmed';
    }
}
