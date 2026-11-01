<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A deployment reported through the API, linked to the monitored
 * resources (server, domain, page, service) it affects. See DeployJudge
 * for the before/after comparison.
 */
class Deployment extends Model
{
    use BelongsToTeam;

    protected $fillable = ['application', 'environment', 'version', 'commit_sha', 'deployed_at', 'source', 'actor', 'url', 'external_id'];

    protected function casts(): array
    {
        return ['deployed_at' => 'datetime'];
    }

    /** @return array<int, array{type: string, id: int}> */
    public function resourceRefs(): array
    {
        return DB::table('deployment_resources')->where('deployment_id', $this->id)->orderBy('id')
            ->get(['resource_type', 'resource_id'])
            ->map(fn ($r) => ['type' => $r->resource_type, 'id' => (int) $r->resource_id])->all();
    }

    /** Deployments of the current scope linked to this resource. */
    public function scopeForResource(Builder $query, Model $resource): Builder
    {
        return $query->whereIn('id', DB::table('deployment_resources')->select('deployment_id')
            ->where('resource_type', $resource->getMorphClass())->where('resource_id', $resource->getKey()));
    }

    /** A short label: application, version or commit. */
    public function label(): string
    {
        $ref = $this->version ?: ($this->commit_sha ? substr($this->commit_sha, 0, 7) : null);

        return $this->application.($ref ? " {$ref}" : '');
    }
}
