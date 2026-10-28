<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Detects monitoring agents that stopped sending metrics.
 *
 * Metric-based alerts are only evaluated when a sample arrives, so a
 * server that goes down silently never produces one. This service runs
 * from the scheduler and evaluates "agent_offline" alert rules against
 * each server's last heartbeat. Recovery is handled both here and by
 * AlertDetectionService when the next sample arrives.
 */
class AgentHeartbeatService
{
    public const OFFLINE_AFTER_MINUTES = Server::OFFLINE_AFTER_MINUTES;

    /**
     * @return array{marked_offline: int, triggered: int, resolved: int}
     */
    public function check(?CarbonInterface $now = null): array
    {
        $now ??= now();

        $result = [
            'marked_offline' => 0,
            'triggered' => 0,
            'resolved' => 0,
        ];

        $result['marked_offline'] = Server::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<=', $now->copy()->subMinutes(self::OFFLINE_AFTER_MINUTES))
            ->where('status', '!=', 'offline')
            ->update([
                'status' => 'offline',
                'updated_at' => $now,
            ]);

        $rules = AlertRule::query()
            ->where('is_enabled', true)
            ->where('metric', AlertRule::METRIC_AGENT_OFFLINE)
            ->get();

        if ($rules->isEmpty()) {
            return $result;
        }

        // Every team's servers, each evaluated against its own team's rules.
        $rules = $rules->groupBy('team_id');

        Server::query()
            ->whereNotNull('last_seen_at')
            ->select(['id', 'team_id'])
            ->orderBy('id')
            ->chunkById(200, function ($servers) use ($rules, $now, &$result): void {
                foreach ($servers as $server) {
                    foreach ($rules->get($server->team_id, []) as $rule) {
                        $outcome = $this->evaluate($server->id, $rule->id, $now);

                        if ($outcome !== null) {
                            $result[$outcome]++;
                        }
                    }
                }
            });

        return $result;
    }

    /**
     * @return 'triggered'|'resolved'|null
     */
    private function evaluate(
        int $serverId,
        int $ruleId,
        CarbonInterface $now,
    ): ?string {
        return DB::transaction(function () use ($serverId, $ruleId, $now): ?string {
            // Same lock order as AlertDetectionService: server, then rule.
            // Reading last_seen_at under the lock avoids racing an
            // in-flight metric submission.
            $server = Server::query()
                ->whereKey($serverId)
                ->lockForUpdate()
                ->first();

            $rule = AlertRule::query()
                ->whereKey($ruleId)
                ->lockForUpdate()
                ->first();

            if (
                $server === null
                || $rule?->team_id !== $server->team_id
                || $server->last_seen_at === null
                || $rule === null
                || ! $rule->is_enabled
            ) {
                return null;
            }

            $silentMinutes = max(
                0.0,
                $server->last_seen_at->diffInSeconds($now, false) / 60,
            );

            $activeAlert = Alert::query()
                ->where('server_id', $server->id)
                ->where('alert_rule_id', $rule->id)
                ->where('metric', AlertRule::METRIC_AGENT_OFFLINE)
                ->whereNull('dimension')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($silentMinutes < $rule->threshold) {
                if ($activeAlert === null) {
                    return null;
                }

                $activeAlert->update([
                    'status' => 'resolved',
                    'resolved_at' => $now,
                    'observed_value' => round($silentMinutes, 2),
                ]);

                return 'resolved';
            }

            if ($activeAlert !== null) {
                $activeAlert->update([
                    'observed_value' => round($silentMinutes, 2),
                ]);

                return null;
            }

            // Planned maintenance must not raise downtime alerts. Once the
            // window ends a still-silent agent triggers on the next run.
            if ($server->isInMaintenance($now)) {
                return null;
            }

            Alert::query()->create([
                'server_id' => $server->id,
                'alert_rule_id' => $rule->id,
                'metric' => AlertRule::METRIC_AGENT_OFFLINE,
                'dimension' => null,
                'observed_value' => round($silentMinutes, 2),
                'threshold' => $rule->threshold,
                'status' => 'active',
                // The outage started at the last heartbeat.
                'triggered_at' => $server->last_seen_at,
            ]);

            return 'triggered';
        }, attempts: 3);
    }
}
