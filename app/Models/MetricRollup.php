<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An hourly or daily aggregate of one server metric (see
 * App\Services\Analytics\MetricRollups). Written only through upserts by
 * the rollup job, never through Eloquent; the model is for reading.
 */
class MetricRollup extends Model
{
    use BelongsToTeam;

    public const HOUR = 'hour';

    public const DAY = 'day';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'count' => 'integer',
            'sum' => 'float',
            'min' => 'float',
            'max' => 'float',
        ];
    }

    public function owningTeamId(): ?int
    {
        return Server::query()->withoutGlobalScopes()->whereKey($this->server_id)->value('team_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function mean(): float
    {
        return $this->count > 0 ? $this->sum / $this->count : 0.0;
    }
}
