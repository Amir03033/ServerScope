<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'record_type', 'previous', 'current', 'added', 'removed', 'detected_at'];

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'current' => 'array',
            'added' => 'array',
            'removed' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class)->withTrashed();
    }
}
