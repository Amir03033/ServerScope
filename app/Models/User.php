<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
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
            'notify_email' => 'boolean',
            'notify_in_app' => 'boolean',
            'notify_resolved' => 'boolean',
            'notify_dns_changes' => 'boolean',
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

    private const SEVERITY_RANK = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /** Whether this user wants notifications for incidents of a severity. */
    public function wantsSeverity(string $severity): bool
    {
        return (self::SEVERITY_RANK[$severity] ?? 0) >= (self::SEVERITY_RANK[$this->notify_min_severity ?? 'warning'] ?? 1);
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
