<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use SoftDeletes;

    /** Matches the agent heartbeat logic in AgentHeartbeatService. */
    public const OFFLINE_AFTER_MINUTES = 2;

    /** @var array<string, string> */
    public const ENVIRONMENTS = [
        'production' => 'Productie',
        'staging' => 'Staging',
        'development' => 'Ontwikkeling',
        'testing' => 'Test',
        'other' => 'Overig',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'online' => 'Online',
        'offline' => 'Offline',
        'pending' => 'In afwachting',
        'maintenance' => 'Onderhoud',
    ];

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'operating_system',
        'environment',
        'notes',
        'agent_version',
        'status',
        'last_seen_at',
        'maintenance_starts_at',
        'maintenance_ends_at',
        'maintenance_reason',
        'notifications_enabled',
        'sla_target',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'notifications_enabled' => 'boolean',
            'sla_target' => 'float',
            'maintenance_starts_at' => 'datetime',
            'maintenance_ends_at' => 'datetime',
        ];
    }

    public function agentCredentials(): HasMany
    {
        return $this->hasMany(AgentCredential::class);
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function latestMetricSample(): HasOne
    {
        return $this->hasOne(MetricSample::class)
            ->latestOfMany('collected_at');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function isInMaintenance(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->maintenance_starts_at !== null
            && $this->maintenance_starts_at->lte($at)
            && ($this->maintenance_ends_at === null || $this->maintenance_ends_at->gt($at));
    }

    public function hasScheduledMaintenance(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->maintenance_starts_at !== null
            && $this->maintenance_starts_at->gt($at);
    }

    /**
     * The status shown in the interface: maintenance, pending, offline
     * or online based on the last heartbeat.
     */
    public function displayStatus(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return match (true) {
            $this->isInMaintenance($at) => 'maintenance',
            $this->last_seen_at === null => 'pending',
            $this->last_seen_at->lte($at->copy()->subMinutes(self::OFFLINE_AFTER_MINUTES)) => 'offline',
            default => 'online',
        };
    }

    public function environmentLabel(): string
    {
        return self::ENVIRONMENTS[$this->environment] ?? ucfirst((string) $this->environment);
    }

    public function scopeInMaintenance(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereNotNull('maintenance_starts_at')
            ->where('maintenance_starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q
                ->whereNull('maintenance_ends_at')
                ->orWhere('maintenance_ends_at', '>', $at));
    }

    public function scopeNotInMaintenance(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(fn (Builder $q) => $q
            ->whereNull('maintenance_starts_at')
            ->orWhere('maintenance_starts_at', '>', $at)
            ->orWhere(fn (Builder $q) => $q
                ->whereNotNull('maintenance_ends_at')
                ->where('maintenance_ends_at', '<=', $at)));
    }

    /**
     * Filter by display status (see displayStatus()).
     */
    public function scopeWithDisplayStatus(Builder $query, string $status, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();
        $offlineBefore = $at->copy()->subMinutes(self::OFFLINE_AFTER_MINUTES);

        return match ($status) {
            'maintenance' => $query->inMaintenance($at),
            'pending' => $query->notInMaintenance($at)->whereNull('last_seen_at'),
            'offline' => $query->notInMaintenance($at)->where('last_seen_at', '<=', $offlineBefore),
            'online' => $query->notInMaintenance($at)->where('last_seen_at', '>', $offlineBefore),
            default => $query,
        };
    }
}
