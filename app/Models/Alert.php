<?php

namespace App\Models;

use App\Observers\AlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(AlertObserver::class)]
class Alert extends Model
{
    use \App\Tenancy\BelongsToTeam;

    /** An alert belongs to the team of its server. */
    public function owningTeamId(): ?int
    {
        return $this->server_id ? Server::query()->withoutGlobalScopes()->whereKey($this->server_id)->value('team_id') : null;
    }

    protected $fillable = [
        'server_id',
        'alert_rule_id',
        'incident_id',
        'metric',
        'dimension',
        'observed_value',
        'threshold',
        'status',
        'recovery_streak',
        'triggered_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_value' => 'float',
            'threshold' => 'float',
            'recovery_streak' => 'integer',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class)->withTrashed();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Ends the active alerts of servers that were removed (soft deleted):
     * nothing evaluates a removed server any more, so they would stay
     * active for ever and could not be cleared. One server, or all of them.
     *
     * @return int the number of alerts ended
     */
    public static function endForRemovedServers(?int $serverId = null): int
    {
        $removed = Server::query()->withoutGlobalScopes()->whereNotNull('deleted_at')->select('id');
        $ended = 0;

        static::query()->withoutGlobalScopes()->where('status', 'active')
            ->when($serverId !== null, fn ($q) => $q->where('server_id', $serverId), fn ($q) => $q->whereIn('server_id', $removed))
            ->get()
            ->each(function (self $alert) use (&$ended): void {
                // Through the model, so AlertObserver closes the incident as well.
                $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
                $ended++;
            });

        return $ended;
    }

    /** Whether the alert's server was removed (or no longer exists). */
    public function serverRemoved(): bool
    {
        return ! Server::query()->withoutGlobalScopes()->whereKey($this->server_id)->whereNull('deleted_at')->exists();
    }
}
