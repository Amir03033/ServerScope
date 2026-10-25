<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'domain_id', 'type', 'result', 'success', 'response_time_ms', 'http_status_code',
        'error_code', 'message', 'details', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'response_time_ms' => 'float',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class)->withTrashed();
    }
}
