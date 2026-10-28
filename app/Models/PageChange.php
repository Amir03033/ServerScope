<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A detected change of a page's monitored content or of its monitored
 * response headers (configuration drift). Changes are never incidents by
 * themselves; users acknowledge expected ones.
 */
class PageChange extends Model
{
    use \App\Tenancy\ScopedThroughParent;

    /** @return array{0: string, 1: class-string} */
    protected static function teamParent(): array
    {
        return ['domain_id', Domain::class];
    }

    public $timestamps = false;

    protected $fillable = [
        'monitored_page_id', 'domain_id', 'kind', 'previous_fingerprint', 'new_fingerprint', 'significance',
        'change_ratio', 'summary', 'diff', 'acknowledged_at', 'acknowledged_by', 'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'change_ratio' => 'float',
            'acknowledged_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
