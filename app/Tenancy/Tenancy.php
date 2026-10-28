<?php

namespace App\Tenancy;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Tenancy
{
    public const ROLES = ['owner' => 'Eigenaar', 'admin' => 'Beheerder', 'member' => 'Lid'];

    public const ADMIN_ROLES = ['owner', 'admin'];

    /**
     * The team a record belongs to: its own team_id, the team of its
     * parent, or the current team of a user. Null when unknown.
     */
    public static function teamIdOf(?Model $model): ?int
    {
        if ($model === null) {
            return null;
        }

        if ($model instanceof User) {
            return $model->current_team_id ?? $model->teams()->orderBy('teams.id')->value('teams.id');
        }

        if ($model->getAttribute('team_id') !== null) {
            return (int) $model->getAttribute('team_id');
        }

        return method_exists($model, 'owningTeamId') ? $model->owningTeamId() : null;
    }

    /** The team of a resource addressed by morph type and id (any team, also trashed). */
    public static function teamIdOfMorph(?string $type, int|string|null $id): ?int
    {
        $class = $type !== null ? \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) : null;

        if ($class === null || $id === null) {
            return null;
        }

        $resource = $class::query()->withoutGlobalScopes()->find($id);

        return self::teamIdOf($resource);
    }

    /**
     * Only automated tests may fall back to the default team for records
     * created without any team context (fixtures written before teams
     * existed). Everywhere else a missing team is an error.
     */
    public static function fallbackTeamId(): ?int
    {
        if (! app()->runningUnitTests() || ! config('serverscope.tenancy.test_fallback', true)) {
            return null;
        }

        return Team::query()->where('is_default', true)->value('id');
    }

    /** The team for a new record, or an exception when none can be determined. */
    public static function requireTeamId(?int $preferred, string $what): int
    {
        $teamId = $preferred ?? Tenant::id() ?? self::fallbackTeamId();

        if ($teamId === null) {
            throw MissingTeamException::for($what);
        }

        return $teamId;
    }
}
