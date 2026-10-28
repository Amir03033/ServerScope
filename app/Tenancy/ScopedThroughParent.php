<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * For child models without their own team_id that can be addressed
 * directly (route model binding, lists): within a tenant they are
 * limited to rows whose parent belongs to that tenant. The parent query
 * carries the parent's TeamScope.
 *
 * Using models define: protected static function teamParent(): array
 * returning [foreign key column, parent model class].
 */
trait ScopedThroughParent
{
    public static function bootScopedThroughParent(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (! Tenant::check()) {
                return;
            }

            [$column, $parent] = static::teamParent();
            $query = $parent::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($parent), true)) {
                $query->withTrashed();
            }

            $builder->whereIn($builder->qualifyColumn($column), $query->select($query->getModel()->getQualifiedKeyName()));
        });
    }

    public function owningTeamId(): ?int
    {
        [$column, $parent] = static::teamParent();

        return $this->getAttribute($column) === null
            ? null
            : $parent::query()->withoutGlobalScopes()->whereKey($this->getAttribute($column))->value('team_id');
    }
}
