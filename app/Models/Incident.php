<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Incident extends Model
{
    public const STATUSES = ['open', 'acknowledged', 'investigating', 'resolved', 'closed'];

    public const ACTIVE_STATUSES = ['open', 'acknowledged', 'investigating'];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    /** Allowed manual status transitions. */
    public const TRANSITIONS = [
        'open' => ['acknowledged', 'investigating', 'resolved'],
        'acknowledged' => ['investigating', 'resolved'],
        'investigating' => ['acknowledged', 'resolved'],
        'resolved' => ['open', 'closed'],
        'closed' => [],
    ];

    public const SOURCE_LABELS = [
        'alert' => 'Resource-alert',
        'agent' => 'Agent offline',
        'http' => 'HTTP-beschikbaarheid',
        'ssl' => 'SSL/TLS-certificaat',
        'ssl_expiry' => 'Certificaat verloopt',
        'dns' => 'DNS',
        'expiry' => 'Domeinregistratie',
        'manual' => 'Handmatig',
    ];

    protected $fillable = [
        'fingerprint', 'active_fingerprint', 'subject_type', 'subject_id', 'source', 'severity',
        'status', 'title', 'description', 'assigned_to', 'opened_at', 'last_occurrence_at',
        'occurrence_count', 'acknowledged_at', 'acknowledged_by', 'resolved_at', 'resolved_by',
        'closed_at', 'closed_by', 'resolution_notes', 'reopened_count', 'escalated_at',
        'last_notified_at', 'context',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'last_occurrence_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'occurrence_count' => 'integer',
            'reopened_count' => 'integer',
            'context' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? ucfirst($this->source);
    }

    /** Seconds from detection to first acknowledgement. */
    public function timeToAcknowledge(): ?int
    {
        return $this->acknowledged_at ? (int) $this->opened_at->diffInSeconds($this->acknowledged_at, true) : null;
    }

    /** Seconds from detection to resolution. */
    public function timeToResolve(): ?int
    {
        return $this->resolved_at ? (int) $this->opened_at->diffInSeconds($this->resolved_at, true) : null;
    }

    public function subjectName(): string
    {
        $subject = $this->subject;

        return match (true) {
            $subject instanceof Server => $subject->name,
            $subject instanceof Domain => $subject->displayName(),
            default => '—',
        };
    }
}
