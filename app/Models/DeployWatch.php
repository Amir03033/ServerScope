<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A git checkout an agent watches (SERVERSCOPE_DEPLOY_PATHS). The agent
 * only reads .git/HEAD; when the commit changes, that is a deployment.
 * Written only by DeployWatcher.
 */
class DeployWatch extends Model
{
    use BelongsToTeam;

    protected $fillable = ['server_id', 'name'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
