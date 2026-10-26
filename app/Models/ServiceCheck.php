<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A service (systemd unit or process) on a server, as reported by the
 * agent. Only services configured on the agent exist here: nothing is
 * assumed to run on every server.
 */
class ServiceCheck extends Model
{
    public const KINDS = [
        'nginx' => 'Nginx',
        'apache' => 'Apache',
        'mysql' => 'MySQL / MariaDB',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'php-fpm' => 'PHP-FPM',
        'custom' => 'Overig',
    ];

    public const STATUS_LABELS = [
        'unknown' => 'Onbekend',
        'running' => 'Actief',
        'starting' => 'Bezig met starten',
        'stopped' => 'Gestopt',
        'failed' => 'Mislukt',
        'not_found' => 'Niet gevonden',
        'stale' => 'Geen recente gegevens',
    ];

    public const FAILED_STATES = ['stopped', 'failed', 'not_found'];

    protected $fillable = ['server_id', 'name', 'kind', 'display_name', 'is_enabled', 'alerts_enabled', 'severity', 'failure_threshold'];

    protected $attributes = [
        'kind' => 'custom',
        'is_enabled' => true,
        'alerts_enabled' => true,
        'severity' => 'high',
        'failure_threshold' => 2,
        'status' => 'unknown',
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'alerts_enabled' => 'boolean',
            'failure_threshold' => 'integer',
            'consecutive_failures' => 'integer',
            'last_reported_at' => 'datetime',
            'last_running_at' => 'datetime',
            'failing_since' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServiceCheckEvent::class);
    }

    public function incidents(): MorphMany
    {
        return $this->morphMany(Incident::class, 'subject');
    }

    public function displayName(): string
    {
        $name = $this->display_name ?: $this->name;

        return $this->server ? "{$name} op {$this->server->name}" : $name;
    }

    /** Guess the kind from a unit or process name. */
    public static function guessKind(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_contains($name, 'nginx') => 'nginx',
            str_contains($name, 'apache'), str_contains($name, 'httpd') => 'apache',
            str_contains($name, 'mysql'), str_contains($name, 'mariadb') => 'mysql',
            str_contains($name, 'postgres') => 'postgresql',
            str_contains($name, 'redis') => 'redis',
            str_contains($name, 'fpm') => 'php-fpm',
            default => 'custom',
        };
    }

    public function isFailing(): bool
    {
        return in_array($this->status, self::FAILED_STATES, true);
    }
}
