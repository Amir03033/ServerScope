<?php

namespace App\Tenancy;

use App\Models\Team;

/**
 * Static access to the current tenant (see TenantContext).
 */
class Tenant
{
    public static function id(): ?int
    {
        return app(TenantContext::class)->id();
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function set(Team|int|null $team): void
    {
        app(TenantContext::class)->set($team instanceof Team ? $team->id : $team);
    }

    public static function forget(): void
    {
        self::set(null);
    }

    /**
     * Run a callback as the given team (or without a tenant for null) and
     * restore the previous tenant afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Team|int|null $team, callable $callback): mixed
    {
        $previous = self::id();
        self::set($team);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
