<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation the recipient accepts. The token only exists in the mail;
 * the database holds its SHA-256.
 */
class TeamInvitation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array{0: self, 1: string} the invitation and its plain token */
    public static function issue(Team $team, string $email, string $role, ?string $name, ?int $by): array
    {
        // A new invitation replaces earlier pending ones for the same address.
        static::query()->where('team_id', $team->id)->where('email', $email)->whereNull('accepted_at')->delete();

        $plain = Str::random(48);
        $invitation = static::query()->create([
            'team_id' => $team->id, 'email' => $email, 'name' => $name, 'role' => $role,
            'token_hash' => hash('sha256', $plain), 'invited_by' => $by, 'expires_at' => now()->addDays(7),
        ]);

        return [$invitation, $plain];
    }

    public static function findValid(string $plain): ?self
    {
        $invitation = static::query()->where('token_hash', hash('sha256', $plain))->first();

        return $invitation && $invitation->accepted_at === null && $invitation->expires_at->isFuture() ? $invitation : null;
    }
}
