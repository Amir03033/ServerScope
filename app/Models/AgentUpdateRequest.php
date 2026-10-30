<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to update the agent of one server to one signed release,
 * and the result the updater reported.
 */
class AgentUpdateRequest extends Model
{
    use \App\Tenancy\BelongsToTeam;

    public const STATUS_LABELS = [
        'pending' => 'Wachtend',
        'in_progress' => 'Bezig',
        'succeeded' => 'Gelukt',
        'rolled_back' => 'Mislukt en teruggezet',
        'rejected' => 'Geweigerd',
        'failed' => 'Mislukt',
        'expired' => 'Verlopen',
    ];

    public const OPEN = ['pending', 'in_progress'];

    /** Reasons the updater may report (fixed codes, never free text). */
    public const REASONS = [
        'bad_signature' => 'ongeldige handtekening',
        'bad_manifest' => 'ongeldig manifest',
        'bad_checksum' => 'tarball klopt niet met het manifest',
        'bad_archive' => 'onverwachte inhoud in de tarball',
        'version_mismatch' => 'andere versie dan gevraagd',
        'downgrade' => 'niet nieuwer dan de geïnstalleerde versie',
        'already_failed' => 'deze versie is hier al eerder mislukt',
        'updater_too_old' => 'updateprogramma te oud (draai install.sh opnieuw)',
        'download_failed' => 'downloaden mislukt',
        'install_failed' => 'installeren mislukt',
        'no_sample' => 'geen meting van de nieuwe versie',
        'disabled_locally' => 'updates lokaal uitgeschakeld',
    ];

    /** Set by ServerScope itself. */
    public const SERVER_REASONS = [
        'withdrawn' => 'release teruggetrokken',
        'stale' => 'niet opgehaald door het updateprogramma',
        'timeout' => 'geen resultaat van het updateprogramma',
        'already_installed' => 'deze versie draait al',
        'superseded' => 'vervangen door een nieuwer verzoek',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function owningTeamId(): ?int
    {
        return $this->server_id ? Server::withoutGlobalScopes()->withTrashed()->whereKey($this->server_id)->value('team_id') : null;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->withTrashed();
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(AgentRelease::class, 'agent_release_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function reasonLabel(): ?string
    {
        return $this->reason === null ? null : (self::REASONS[$this->reason] ?? self::SERVER_REASONS[$this->reason] ?? $this->reason);
    }
}
