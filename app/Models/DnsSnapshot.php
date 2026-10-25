<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'record_type', 'records', 'hash', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'records' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
