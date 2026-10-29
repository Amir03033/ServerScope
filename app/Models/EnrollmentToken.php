<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time token with which install.sh enrolls a new server into the
 * token's team. Valid until it expires, is used once, or is revoked.
 */
class EnrollmentToken extends Model
{
    use \App\Tenancy\BelongsToTeam;

    protected $fillable = ['label', 'prefix', 'token_hash', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reported' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->withTrashed();
    }

    /** Not used, not revoked, not expired. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** waiting, enrolled (server created), connected (first sample), expired or revoked. */
    public function state(): string
    {
        if ($this->used_at !== null) {
            return $this->server?->last_seen_at !== null ? 'connected' : 'enrolled';
        }

        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at->isFuture() ? 'waiting' : 'expired';
    }
}
