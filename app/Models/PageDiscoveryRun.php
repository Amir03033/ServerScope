<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageDiscoveryRun extends Model
{
    use \App\Tenancy\ScopedThroughParent;

    /** @return array{0: string, 1: class-string} */
    protected static function teamParent(): array
    {
        return ['domain_id', Domain::class];
    }

    protected $fillable = [
        'domain_id', 'triggered_by', 'status', 'settings', 'requests_made', 'urls_found', 'pages_added',
        'pages_seen', 'urls_skipped', 'sources', 'errors', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sources' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class)->withTrashed();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
