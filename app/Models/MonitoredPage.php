<?php

namespace App\Models;

use App\Support\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One monitored page of a website (domain). Pages have their own checks,
 * thresholds, alert settings and history; discovered pages wait for
 * approval unless the website is configured to monitor them directly.
 */
class MonitoredPage extends Model
{
    public const STATUSES = ['unknown', 'up', 'degraded', 'down'];

    public const APPROVAL_STATUSES = ['approved', 'pending', 'rejected'];

    public const SOURCES = [
        'manual' => 'Handmatig',
        'sitemap' => 'Sitemap',
        'robots' => 'robots.txt',
        'link' => 'Interne link',
    ];

    /** Failure types (see PageMonitor). */
    public const FAILURE_LABELS = [
        'network' => 'Netwerkfout',
        'timeout' => 'Time-out',
        'dns' => 'DNS-fout',
        'tls' => 'TLS-fout',
        'blocked' => 'Geblokkeerd doel',
        'redirect' => 'Redirectfout',
        'status' => 'Onverwachte statuscode',
        'content' => 'Inhoudscontrole mislukt',
        'header' => 'Headercontrole mislukt',
        'slow' => 'Traag',
    ];

    public const STATUS_LABELS = [
        'unknown' => 'Onbekend',
        'up' => 'Bereikbaar',
        'degraded' => 'Verminderd',
        'down' => 'Onbereikbaar',
    ];

    protected $fillable = [
        'domain_id', 'url', 'url_hash', 'title', 'source', 'approval_status', 'depth',
        'is_active', 'paused_at', 'check_interval_minutes', 'timeout_seconds', 'failure_threshold',
        'recovery_threshold', 'expected_status_codes', 'max_response_ms', 'follow_redirects',
        'expected_final_url', 'keyword_present', 'keyword_absent', 'header_assertions', 'monitored_headers',
        'content_monitoring', 'content_selector', 'content_exclusions', 'alerts_enabled',
        'alert_severity', 'notify_content_changes', 'sla_target', 'discovered_at', 'last_seen_in_discovery_at',
        'next_check_at',
    ];

    protected $hidden = ['content_snapshot'];

    protected $attributes = [
        'source' => 'manual',
        'approval_status' => 'approved',
        'is_active' => true,
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'failure_threshold' => 2,
        'recovery_threshold' => 2,
        'follow_redirects' => true,
        'content_monitoring' => false,
        'alerts_enabled' => true,
        'alert_severity' => Severity::HIGH,
        'notify_content_changes' => false,
        'status' => 'unknown',
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'follow_redirects' => 'boolean',
            'content_monitoring' => 'boolean',
            'alerts_enabled' => 'boolean',
            'notify_content_changes' => 'boolean',
            'tls_valid' => 'boolean',
            'header_assertions' => 'array',
            'monitored_headers' => 'array',
            'header_snapshot' => 'array',
            'content_exclusions' => 'array',
            'depth' => 'integer',
            'check_interval_minutes' => 'integer',
            'timeout_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'recovery_threshold' => 'integer',
            'max_response_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_status_code' => 'integer',
            'last_response_ms' => 'float',
            'sla_target' => 'float',
            'paused_at' => 'datetime',
            'content_changed_at' => 'datetime',
            'failing_since' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'next_check_at' => 'datetime',
            'discovered_at' => 'datetime',
            'last_seen_in_discovery_at' => 'datetime',
        ];
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class)->withTrashed();
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PageCheck::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(PageChange::class);
    }

    public function incidents(): MorphMany
    {
        return $this->morphMany(Incident::class, 'subject');
    }

    public function displayName(): string
    {
        $parts = parse_url($this->url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $host = $parts['host'] ?? '';

        // Pages on the website's own host are shown by path.
        return $this->domain && $host === $this->domain->name ? $path : $host.$path;
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->url), 'https://');
    }

    public function isMonitored(): bool
    {
        return $this->is_active && $this->approval_status === 'approved';
    }

    /** Status shown in the interface (adds paused, pending and rejected). */
    public function displayStatus(): string
    {
        return match (true) {
            $this->approval_status === 'pending' => 'pending',
            $this->approval_status === 'rejected' => 'rejected',
            ! $this->is_active => 'paused',
            default => $this->status,
        };
    }

    /**
     * Whether a status code is acceptable: expected_status_codes such as
     * "200-299,301"; default 200-399 (as for domains).
     */
    public function acceptsStatus(int $code): bool
    {
        return (new Domain(['expected_status_codes' => $this->expected_status_codes]))->acceptsStatus($code);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('approval_status', 'approved');
    }
}
