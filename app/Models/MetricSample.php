<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSample extends Model
{
    protected $fillable = [
        'server_id',
        'cpu_percent',
        'load_1',
        'load_5',
        'load_15',
        'memory_total_bytes',
        'memory_used_bytes',
        'memory_available_bytes',
        'swap_total_bytes',
        'swap_used_bytes',
        'disks',
        'disk_read_bytes',
        'disk_write_bytes',
        'network_rx_bytes',
        'network_tx_bytes',
        'network_errors_in',
        'network_errors_out',
        'network_drops_in',
        'network_drops_out',
        'uptime_seconds',
        'process_count',
        'agent_version',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
            'disks' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
