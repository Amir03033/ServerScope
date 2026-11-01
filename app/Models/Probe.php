<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A remote probe (platform infrastructure, shared by all teams like the
 * main monitor). Created with serverscope:probe:create; it polls for
 * confirmation jobs with signed requests (App\Services\Probes\ProbeSignature).
 */
class Probe extends Model
{
    protected $fillable = ['name', 'location', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ProbeCheck::class);
    }

    /** Active and polled recently enough to answer a confirmation job in time. */
    public function isHealthy(): bool
    {
        return $this->is_active && $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds((int) config('serverscope.probe.stale_after_seconds', 180)));
    }

    public static function healthy(): ?self
    {
        return self::query()->where('is_active', true)->orderByDesc('last_seen_at')->get()->first(fn (self $p) => $p->isHealthy());
    }
}
