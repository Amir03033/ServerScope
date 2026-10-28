<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use \App\Tenancy\BelongsToTeam;

    /** A delivery about an incident belongs to the incident's team. */
    public function owningTeamId(): ?int
    {
        return $this->incident_id ? Incident::query()->withoutGlobalScopes()->whereKey($this->incident_id)->value('team_id') : null;
    }

    protected $fillable = ['team_id', 'notification_id', 'user_id', 'recipient', 'incident_id', 'kind', 'channel', 'status', 'subject', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
