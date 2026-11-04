<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a GitHub webhook told us about one commit: its title, author and pull request. */
class GithubCommit extends Model
{
    use BelongsToTeam;

    protected $fillable = ['github_connection_id', 'sha'];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime', 'pr_number' => 'integer'];
    }

    public function owningTeamId(): ?int
    {
        return GithubConnection::query()->withoutGlobalScopes()->whereKey($this->github_connection_id)->value('team_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class, 'github_connection_id');
    }
}
