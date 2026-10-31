<?php

namespace App\Support;

use App\Models\Team;
use App\Tenancy\Tenant;

/**
 * The single place that knows what a plan allows. Limits live in
 * config/serverscope.php ('plans'). A team on Pro or Premium whose
 * payment is still pending gets the Free limits until it is active.
 * The default team (data from before multi-tenancy, i.e. the operator's
 * own) is exempt and only bound by the global caps.
 */
class PlanLimits
{
    public const FREE = 'free';

    public const PLANS = ['free', 'pro', 'premium'];

    public const ACTIVE = 'active';

    public const PENDING_PAYMENT = 'pending_payment';

    /** Whitelist: anything unknown becomes free. */
    public static function normalize(mixed $plan): string
    {
        $plan = is_string($plan) ? strtolower(trim($plan)) : '';

        return in_array($plan, self::PLANS, true) ? $plan : self::FREE;
    }

    /** The plan whose limits apply right now. */
    public static function effectivePlan(?Team $team): string
    {
        if ($team === null || $team->plan_status !== self::ACTIVE) {
            return self::FREE;
        }

        return self::normalize($team->plan);
    }

    public static function currentTeam(): ?Team
    {
        $id = Tenant::id();

        return $id === null ? null : Team::query()->find($id);
    }

    public static function isExempt(?Team $team): bool
    {
        return (bool) $team?->is_default;
    }

    /** @return int|null null = unlimited */
    public static function limit(string $key, ?Team $team = null): int|float|null
    {
        $team ??= self::currentTeam();

        // No team in scope (a background job without tenant): only the
        // global caps apply; callers that know the team pass it in.
        if ($team === null || self::isExempt($team)) {
            return null;
        }

        $value = config('serverscope.plans.'.self::effectivePlan($team).'.limits.'.$key);

        return $value === null ? null : (int) $value;
    }

    public static function allows(string $feature, ?Team $team = null): bool
    {
        $team ??= self::currentTeam();

        if ($team === null || self::isExempt($team)) {
            return true;
        }

        return in_array($feature, (array) config('serverscope.plans.'.self::effectivePlan($team).'.features', []), true);
    }

    /** True when $current items already fill the limit (so one more is refused). */
    public static function reached(string $key, int $current, ?Team $team = null): bool
    {
        $limit = self::limit($key, $team);

        return $limit !== null && $current >= $limit;
    }

    /** Shortest allowed check interval: the stricter of the global and the plan minimum. */
    public static function minIntervalMinutes(?Team $team = null): int
    {
        return max((int) config('serverscope.monitoring.min_interval_minutes', 1), (int) (self::limit('min_interval_minutes', $team) ?? 1));
    }

    /** Pages per website: the stricter of the global cap and the plan limit. */
    public static function pagesPerWebsite(?Team $team = null): int
    {
        $global = (int) config('serverscope.websites.max_pages_per_website', 1000);
        $plan = self::limit('pages_per_website', $team);

        return $plan === null ? $global : min($global, (int) $plan);
    }

    /**
     * Resources beyond the limits of the current plan (after a downgrade or
     * a failed payment). They keep working; only new ones are refused.
     *
     * @return array<string, array{count: int, limit: int}>
     */
    public static function overLimit(?Team $team = null): array
    {
        $team ??= self::currentTeam();
        $counts = [
            'servers' => fn () => \App\Models\Server::query()->count(),
            'domains' => fn () => \App\Models\Domain::query()->count(),
            'status_pages' => fn () => \App\Models\StatusPage::query()->count(),
        ];
        $over = [];

        foreach ($counts as $key => $count) {
            $limit = self::limit($key, $team);

            if ($limit !== null && ($n = $count()) > $limit) {
                $over[$key] = ['count' => $n, 'limit' => (int) $limit];
            }
        }

        return $over;
    }

    public static function pendingPayment(?Team $team): bool
    {
        return $team !== null && $team->plan !== self::FREE && $team->plan_status === self::PENDING_PAYMENT;
    }
}
