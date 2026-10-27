<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitored_page_id', 'domain_id', 'result', 'success', 'failure_type', 'http_status_code',
        'response_time_ms', 'error_code', 'message', 'content_changed', 'details', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'content_changed' => 'boolean',
            'response_time_ms' => 'float',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }
}
