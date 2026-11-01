<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Domain extends Model
{
    use \App\Tenancy\BelongsToTeam;

    use SoftDeletes;

    public const DEFAULT_WARNING_DAYS = [30, 14, 7, 3, 1];

    /** TLS states that are a certificate problem (not a connectivity one). */
    public const SSL_INVALID_STATES = ['expired', 'not_yet_valid', 'hostname_mismatch', 'untrusted', 'self_signed'];

    public const STATUS_LABELS = [
        'healthy' => 'Gezond',
        'warning' => 'Waarschuwing',
        'critical' => 'Kritiek',
        'unknown' => 'Onbekend',
        'paused' => 'Gepauzeerd',
        'maintenance' => 'Onderhoud',
    ];

    protected $fillable = [
        'name', 'scheme', 'path', 'notes', 'created_by',
        'is_active', 'paused_at', 'check_interval_minutes', 'timeout_seconds',
        'failure_threshold', 'recovery_threshold', 'expected_status_codes', 'expected_content',
        'follow_redirects', 'http_enabled', 'ssl_enabled', 'dns_enabled', 'expiry_enabled',
        'ssl_warning_days', 'expiry_warning_days',
        'maintenance_starts_at', 'maintenance_ends_at', 'maintenance_reason',
        'notifications_enabled', 'sla_target', 'discovery_settings',
    ];

    protected $attributes = [
        'scheme' => 'https',
        'path' => '/',
        'is_active' => true,
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'failure_threshold' => 3,
        'recovery_threshold' => 2,
        'follow_redirects' => true,
        'http_enabled' => true,
        'ssl_enabled' => true,
        'dns_enabled' => true,
        'expiry_enabled' => true,
        'status' => 'unknown',
        'http_status' => 'unknown',
        'ssl_status' => 'unknown',
        'dns_status' => 'unknown',
        'expiry_status' => 'unknown',
    ];

    protected static function booted(): void
    {
        // Personal favourites have no foreign key to the (polymorphic)
        // resource: remove them when the resource is permanently deleted.
        // A soft delete keeps them, so a restore brings them back.
        static::forceDeleted(fn (self $resource) => Favorite::forgetResource($resource));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'notifications_enabled' => 'boolean',
            'sla_target' => 'float',
            'follow_redirects' => 'boolean',
            'http_enabled' => 'boolean',
            'ssl_enabled' => 'boolean',
            'dns_enabled' => 'boolean',
            'expiry_enabled' => 'boolean',
            'ssl_warning_days' => 'array',
            'expiry_warning_days' => 'array',
            'discovery_settings' => 'array',
            'check_interval_minutes' => 'integer',
            'timeout_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'recovery_threshold' => 'integer',
            'http_consecutive_failures' => 'integer',
            'http_consecutive_successes' => 'integer',
            'dns_consecutive_failures' => 'integer',
            'http_last_response_ms' => 'float',
            'paused_at' => 'datetime',
            'maintenance_starts_at' => 'datetime',
            'maintenance_ends_at' => 'datetime',
            'http_last_checked_at' => 'datetime',
            'http_last_success_at' => 'datetime',
            'http_next_check_at' => 'datetime',
            'ssl_valid_from' => 'datetime',
            'ssl_valid_to' => 'datetime',
            'ssl_last_checked_at' => 'datetime',
            'ssl_next_check_at' => 'datetime',
            'dns_last_checked_at' => 'datetime',
            'dns_last_changed_at' => 'datetime',
            'dns_next_check_at' => 'datetime',
            'registration_expires_at' => 'datetime',
            'registered_at' => 'datetime',
            'expiry_checked_at' => 'datetime',
            'expiry_next_check_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(DomainCheck::class);
    }

    public function dnsSnapshots(): HasMany
    {
        return $this->hasMany(DnsSnapshot::class);
    }

    public function dnsChanges(): HasMany
    {
        return $this->hasMany(DnsChange::class);
    }

    public function incidents(): MorphMany
    {
        return $this->morphMany(Incident::class, 'subject');
    }

    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(MonitoredPage::class);
    }

    public function pageChecks(): HasMany
    {
        return $this->hasMany(PageCheck::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(PageDiscoveryRun::class);
    }

    /**
     * Discovery configuration with safe defaults: few pages, shallow,
     * same host only, discovered pages wait for approval.
     *
     * @return array{max_pages: int, max_depth: int, allowed_patterns: array<int, string>, excluded_patterns: array<int, string>, keep_query: bool, include_subdomains: bool, auto_monitor: bool, default_interval: int, sitemap_urls: array<int, string>}
     */
    public function discoverySettings(): array
    {
        $settings = $this->discovery_settings ?? [];
        $limit = (int) config('serverscope.websites.discovery_max_pages_limit', 1000);

        return [
            'max_pages' => max(1, min($limit, (int) ($settings['max_pages'] ?? config('serverscope.websites.discovery_default_max_pages', 50)))),
            'max_depth' => max(0, min(5, (int) ($settings['max_depth'] ?? 1))),
            'allowed_patterns' => array_values(array_filter((array) ($settings['allowed_patterns'] ?? []), 'is_string')),
            'excluded_patterns' => array_values(array_filter((array) ($settings['excluded_patterns'] ?? []), 'is_string')),
            'keep_query' => (bool) ($settings['keep_query'] ?? false),
            'include_subdomains' => (bool) ($settings['include_subdomains'] ?? false),
            'auto_monitor' => (bool) ($settings['auto_monitor'] ?? false),
            'default_interval' => max(1, min(1440, (int) ($settings['default_interval'] ?? 15))),
            'sitemap_urls' => array_values(array_filter((array) ($settings['sitemap_urls'] ?? []), 'is_string')),
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function url(): string
    {
        return $this->scheme.'://'.$this->name.($this->path ?: '/');
    }

    /** Unicode form of an IDN for display. */
    public function displayName(): string
    {
        if (str_contains($this->name, 'xn--') && function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($this->name, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

            if (is_string($unicode) && $unicode !== '') {
                return $unicode;
            }
        }

        return $this->name;
    }

    public function isInMaintenance(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->maintenance_starts_at !== null
            && $this->maintenance_starts_at->lte($at)
            && ($this->maintenance_ends_at === null || $this->maintenance_ends_at->gt($at));
    }

    /** Status shown in the interface (adds paused and maintenance). */
    public function displayStatus(): string
    {
        return match (true) {
            ! $this->is_active => 'paused',
            $this->isInMaintenance() => 'maintenance',
            default => $this->status,
        };
    }

    /** @return array<int, int> descending warning thresholds in days */
    public function sslWarningDays(): array
    {
        return self::normaliseDays($this->ssl_warning_days);
    }

    /** @return array<int, int> */
    public function expiryWarningDays(): array
    {
        return self::normaliseDays($this->expiry_warning_days);
    }

    public static function normaliseDays(?array $days): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', $days ?? self::DEFAULT_WARNING_DAYS),
            fn (int $d) => $d >= 1 && $d <= 365,
        )));
        rsort($days);

        return $days ?: self::DEFAULT_WARNING_DAYS;
    }

    public function sslDaysRemaining(): ?int
    {
        return $this->ssl_valid_to ? (int) floor(now()->diffInSeconds($this->ssl_valid_to, false) / 86400) : null;
    }

    public function expiryDaysRemaining(): ?int
    {
        return $this->registration_expires_at ? (int) floor(now()->diffInSeconds($this->registration_expires_at, false) / 86400) : null;
    }

    /**
     * Whether an HTTP status code is acceptable for this domain.
     * expected_status_codes: e.g. "200-299,301"; default 200-399.
     */
    public function acceptsStatus(int $code): bool
    {
        $spec = trim((string) $this->expected_status_codes) ?: '200-399';

        foreach (explode(',', $spec) as $part) {
            $part = trim($part);

            if (preg_match('/^(\d{3})-(\d{3})$/', $part, $m) && $code >= (int) $m[1] && $code <= (int) $m[2]) {
                return true;
            }

            if (preg_match('/^\d{3}$/', $part) && $code === (int) $part) {
                return true;
            }
        }

        return false;
    }

    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
