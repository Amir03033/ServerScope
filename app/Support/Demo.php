<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The public read-only demo (SERVERSCOPE_DEMO_ENABLED, /demo).
 *
 * Demo teams (teams.is_demo) hold sample data only. For them nothing is
 * checked against the outside world and nothing is sent: no e-mail, push,
 * webhooks, chat messages or AI calls. Demo users (users.is_demo) can read
 * everything but change nothing, and cannot sign in any other way.
 */
class Demo
{
    public const LOCALES = ['en', 'nl'];

    /** Remembers (for a day) that this browser is viewing the demo, so a lost session re-enters it. */
    public const COOKIE = 'ss_demo';

    /** Pages a demo visitor cannot open: money, people, platform and secrets. */
    public const BLOCKED_ROUTES = [
        'billing.*', 'users.*', 'system-health', 'platform.*',
        'api-tokens.*', 'servers.add', 'servers.create', 'enrollment-tokens.*',
    ];

    /** The only changes a demo visitor may make (none of them stored in the database). */
    public const ALLOWED_WRITES = ['logout', 'locale.update'];

    /** @var array<int, int>|null */
    private static ?array $teamIds = null;

    public static function enabled(): bool
    {
        return (bool) config('serverscope.demo.enabled');
    }

    public static function email(string $locale): string
    {
        return "demo-{$locale}@demo.serverscope.invalid";
    }

    /** @return array<int, int> */
    public static function teamIds(): array
    {
        if (self::$teamIds === null) {
            self::$teamIds = Schema::hasColumn('teams', 'is_demo')
                ? Team::query()->where('is_demo', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return self::$teamIds;
    }

    public static function isDemoTeam(Team|int|null $team): bool
    {
        $id = $team instanceof Team ? $team->id : $team;

        return $id !== null && in_array((int) $id, self::teamIds(), true);
    }

    public static function isDemoUser(?User $user): bool
    {
        return (bool) $user?->is_demo;
    }

    /** Forget the cached team list (after seeding, and between tests). */
    public static function flush(): void
    {
        self::$teamIds = null;
    }
}
