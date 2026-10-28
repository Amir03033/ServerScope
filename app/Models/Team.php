<?php

namespace App\Models;

use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A customer (tenant). Every server, domain, incident, rule, webhook,
 * status page, API token and audit entry belongs to exactly one team.
 */
class Team extends Model
{
    protected $fillable = ['name', 'slug'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            $team->slug ??= static::uniqueSlug($team->name);
        });

        // Every team gets the agent-offline rule that the default team got
        // from migration 2026_10_25_120000.
        static::created(function (Team $team): void {
            Tenant::run($team, fn () => AlertRule::query()->create([
                'name' => 'Agent offline',
                'metric' => AlertRule::METRIC_AGENT_OFFLINE,
                'threshold' => 5,
                'operator' => '>=',
                'severity' => 'critical',
                'duration_minutes' => 1,
                'is_enabled' => true,
            ]));
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public static function defaultTeam(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = mb_substr($base, 0, 50);

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = mb_substr($base, 0, 50).'-'.bin2hex(random_bytes(3));
        }

        return $slug;
    }
}
