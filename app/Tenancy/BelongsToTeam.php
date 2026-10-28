<?php

namespace App\Tenancy;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models with a team_id column: queries are limited to the current
 * tenant (TeamScope) and new records get their team from, in order, an
 * explicit team_id, their parent (owningTeamId), or the current tenant.
 * Records never move to another team.
 */
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('team_id') === null) {
                $parent = method_exists($model, 'owningTeamId') ? $model->owningTeamId() : null;
                $model->setAttribute('team_id', Tenancy::requireTeamId($parent, class_basename($model)));
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('team_id') && $model->getOriginal('team_id') !== null) {
                throw new LogicException(class_basename($model).' cannot move to another team.');
            }
        });
    }

    public function initializeBelongsToTeam(): void
    {
        $this->mergeCasts(['team_id' => 'integer']);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
