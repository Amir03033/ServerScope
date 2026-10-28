<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Severity;
use App\Tenancy\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_platform_admin' => 'boolean',
            'notify_email' => 'boolean',
            'notify_in_app' => 'boolean',
            'notify_resolved' => 'boolean',
            'notify_dns_changes' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Administrators are notified by default (as before preferences
        // existed); other users opt in on their profile.
        static::saving(function (User $user): void {
            if ($user->is_admin && ($user->isDirty('is_admin') || ! $user->exists)) {
                if (! $user->isDirty('notify_email')) {
                    $user->notify_email = true;
                }

                if (! $user->isDirty('notify_in_app')) {
                    $user->notify_in_app = true;
                }
            }
        });
    }

    /**
     * Teams this user is a member of; the pivot role (owner, admin,
     * member) is the only source of permissions within a team.
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /** Only meaningful after membership is verified (see ResolveTenant). */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function roleIn(Team|int|null $team): ?string
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        if ($teamId === null || ! $this->exists) {
            return null;
        }

        return $this->teams()->where('teams.id', $teamId)->value('team_user.role');
    }

    public function belongsToTeam(Team|int|null $team): bool
    {
        return $this->roleIn($team) !== null;
    }

    public function isAdminOf(Team|int|null $team): bool
    {
        return in_array($this->roleIn($team), Tenancy::ADMIN_ROLES, true);
    }

    /**
     * users.is_admin mirrors the role in the current team (role -> column,
     * never the other way round) so older code and views that read the
     * column keep showing the right thing. It grants nothing by itself.
     * Quietly skips the "notify administrators by default" rule, which
     * only applies to promotions.
     */
    public function syncAdminMirror(bool $quietly = false): void
    {
        $this->is_admin = $this->isAdminOf($this->current_team_id);

        if ($this->isDirty('is_admin')) {
            $quietly ? $this->saveQuietly() : $this->save();
        }
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Generate new recovery codes; returns the plaintext codes once and
     * stores only their hashes.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $codes = collect(range(1, 8))->map(fn () => strtolower(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))))->all();
        $this->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function useRecoveryCode(string $code): bool
    {
        $hash = hash('sha256', strtolower(trim($code)));
        $codes = $this->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$index]);
                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Whether this user wants notifications for incidents of a severity. */
    public function wantsSeverity(string $severity): bool
    {
        return Severity::atLeast($severity, $this->notify_min_severity ?? Severity::MEDIUM);
    }

    /**
     * Channels this user receives monitoring notifications on.
     *
     * @return array<int, string>
     */
    public function notificationChannels(): array
    {
        return array_values(array_filter([
            $this->notify_email && config('serverscope.alert_mail_enabled') ? 'mail' : null,
            $this->notify_in_app ? 'database' : null,
        ]));
    }
}
