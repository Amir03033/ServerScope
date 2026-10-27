<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A distinct certificate observed for a domain (certificate history). */
class SslCertificate extends Model
{
    public $timestamps = false;

    protected $fillable = ['domain_id', 'fingerprint', 'serial', 'subject', 'issuer', 'subject_alt_names', 'valid_from', 'valid_to', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'subject_alt_names' => 'array',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
