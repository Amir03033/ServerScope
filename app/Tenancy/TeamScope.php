<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current tenant. Without a tenant (scheduler,
 * queue) nothing is filtered: background tasks process every team and
 * select each team's recipients and rules explicitly.
 */
class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::check()) {
            $builder->where($model->qualifyColumn('team_id'), Tenant::id());
        }
    }
}
