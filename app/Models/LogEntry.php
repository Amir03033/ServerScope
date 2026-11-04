<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One error or warning line from a server's log files (see LogIngestController). */
class LogEntry extends Model
{
    use BelongsToTeam;

    public const LEVELS = ['critical', 'error', 'warning'];

    /** Levels counted as errors (the log_errors alert metric, the error counters). */
    public const ERROR_LEVELS = ['critical', 'error'];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function owningTeamId(): ?int
    {
        return Server::query()->withoutGlobalScopes()->whereKey($this->server_id)->value('team_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
