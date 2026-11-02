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
        return ['secret' => 'encrypted', 'is_active' => 'boolean', 'last_seen_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
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

    /** Whether the probe can be placed on the globe. */
    public function isPlaced(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Valid coordinates: latitude -90..90, longitude -180..180 (null when either is missing). */
    public static function coordinates(mixed $latitude, mixed $longitude): ?array
    {
        if ($latitude === null || $latitude === '' || $longitude === null || $longitude === '' || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lon = (float) $longitude;

        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 ? [round($lat, 5), round($lon, 5)] : null;
    }

    public static function healthy(): ?self
    {
        return self::query()->where('is_active', true)->orderByDesc('last_seen_at')->get()->first(fn (self $p) => $p->isHealthy());
    }
}
