<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records administrative actions. Never pass secrets (tokens, passwords,
 * webhook secrets) in $properties.
 */
class Audit
{
    /**
     * Attributes that must never be written to the audit log.
     */
    private const REDACTED = ['password', 'token', 'token_hash', 'secret', 'two_factor_secret', 'remember_token'];

    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
    ): AuditLog {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'properties' => self::redact($properties) ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }

    /**
     * The pending (dirty) attributes of a model with old and new values.
     * Call before saving.
     */
    public static function diff(Model $model): array
    {
        $changes = [];

        foreach ($model->getDirty() as $key => $value) {
            if ($key === 'updated_at') {
                continue;
            }

            $old = $model->getOriginal($key);

            $changes[$key] = [
                'old' => $old instanceof \DateTimeInterface ? $old->format(DATE_ATOM) : $old,
                'new' => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value,
            ];
        }

        return $changes;
    }

    private static function redact(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $properties[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $properties[$key] = self::redact($value);
            }
        }

        return $properties;
    }
}
