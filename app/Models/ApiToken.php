<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use \App\Tenancy\BelongsToTeam;

    public const ABILITIES = ['read' => 'Lezen', 'write' => 'Schrijven (incidenten bevestigen/oplossen, notities)'];

    protected $fillable = ['team_id', 'user_id', 'name', 'prefix', 'token_hash', 'abilities', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Create a token for one team (default: the current tenant); the
     * plaintext value is returned once.
     *
     * @return array{0: ApiToken, 1: string}
     */
    public static function issue(User $user, string $name, array $abilities, ?\DateTimeInterface $expiresAt = null, ?int $teamId = null): array
    {
        $secret = 'ssk_'.bin2hex(random_bytes(24));

        $token = static::query()->create([
            'team_id' => \App\Tenancy\Tenancy::requireTeamId($teamId, 'API token'),
            'user_id' => $user->id,
            'name' => $name,
            'prefix' => substr($secret, 0, 12),
            'token_hash' => hash('sha256', $secret),
            'abilities' => array_values(array_intersect(array_keys(self::ABILITIES), $abilities)),
            'expires_at' => $expiresAt,
        ]);

        return [$token, $secret];
    }
}
