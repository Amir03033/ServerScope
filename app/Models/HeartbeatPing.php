<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ping of a heartbeat: start, success or failure (written only by HeartbeatMonitor). */
class HeartbeatPing extends Model
{
    public const KINDS = ['start', 'success', 'fail'];

    public $timestamps = false;

    protected $fillable = ['heartbeat_id', 'kind', 'exit_code', 'duration_ms', 'body', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'exit_code' => 'integer', 'duration_ms' => 'integer'];
    }

    public function heartbeat(): BelongsTo
    {
        return $this->belongsTo(Heartbeat::class);
    }
}
