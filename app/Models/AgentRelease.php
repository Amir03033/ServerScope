<?php

namespace App\Models;

use App\Releases\ReleaseManifest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed agent release (platform-wide). Created only by
 * serverscope:release:import; never edited apart from withdrawal.
 */
class AgentRelease extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'size' => 'integer',
            'min_updater_version' => 'integer',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Not withdrawn. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('version_major')->orderByDesc('version_minor')->orderByDesc('version_patch');
    }

    public static function latestAvailable(): ?self
    {
        return static::query()->available()->newestFirst()->first();
    }

    public static function findAvailable(mixed $version): ?self
    {
        return ReleaseManifest::isVersion($version) ? static::query()->available()->where('version', $version)->first() : null;
    }

    public function isNewerThan(?string $version): bool
    {
        return ! ReleaseManifest::isVersion($version) || version_compare($this->version, $version, '>');
    }

    public static function directory(string $version): string
    {
        return rtrim((string) config('serverscope.releases.storage_path'), '/').'/'.$version;
    }

    public function path(string $file): string
    {
        return self::directory($this->version).'/'.$file;
    }

    public function tarballPath(): string
    {
        return $this->path($this->filename);
    }
}
