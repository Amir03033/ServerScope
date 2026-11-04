<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The written part of an incident review (see PostmortemBuilder for the
 * facts that are read live). A share token makes a read-only public link.
 */
class Postmortem extends Model
{
    use BelongsToTeam;

    public const MAX_ACTION_ITEMS = 20;

    protected $fillable = ['incident_id', 'summary', 'impact', 'went_well', 'went_wrong', 'action_items'];

    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return ['action_items' => 'array', 'shared_at' => 'datetime'];
    }

    public function owningTeamId(): ?int
    {
        return Incident::query()->withoutGlobalScopes()->whereKey($this->incident_id)->value('team_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isShared(): bool
    {
        return $this->share_token !== null;
    }

    public function publicUrl(): ?string
    {
        return $this->share_token ? route('postmortems.public', $this->share_token) : null;
    }

    /** @return array<int, array{text: string, owner: ?string, done: bool}> */
    public function actionItems(): array
    {
        return array_values(array_filter(array_map(fn ($item) => is_array($item) && trim((string) ($item['text'] ?? '')) !== ''
            ? ['text' => (string) $item['text'], 'owner' => ($item['owner'] ?? null) ?: null, 'done' => (bool) ($item['done'] ?? false)]
            : null, $this->action_items ?? [])));
    }
}
