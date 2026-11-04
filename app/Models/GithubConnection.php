<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub repository connected through a webhook. Its deliveries add
 * commit and pull request details to deployments; with deploy_on "push" a
 * push to the branch is itself a deployment. A successful GitHub
 * deployment status always is. The secret is stored encrypted and shown
 * once, when the connection is made or the secret renewed.
 */
class GithubConnection extends Model
{
    use BelongsToTeam;

    public const DEPLOY_ON = ['agent', 'push'];

    public const EVENTS = ['push', 'pull_request', 'deployment_status'];

    protected $fillable = ['repository', 'application', 'environment', 'deploy_on', 'branch', 'resources'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'resources' => 'array', 'last_delivery_at' => 'datetime'];
    }

    public function commits(): HasMany
    {
        return $this->hasMany(GithubCommit::class);
    }

    public function webhookUrl(): string
    {
        return route('webhooks.github', $this->uuid);
    }

    /** @return array<int, array{type: string, id: int}> */
    public function resourceRefs(): array
    {
        return array_values(array_filter($this->resources ?? [], fn ($r) => is_array($r) && isset($r['type'], $r['id'])));
    }
}
