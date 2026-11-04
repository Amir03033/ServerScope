<?php

namespace App\Services\Heartbeats;

use App\Models\Heartbeat;
use App\Models\HeartbeatPing;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Logs\LogFingerprint;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a ping means and when a job is late.
 *
 * - start: the job began (to measure how long it ran).
 * - success: the job finished; the next ping is expected by the schedule.
 * - fail (or a non-zero exit code): the job reported a failure; an
 *   incident is opened straight away.
 * - A job that stays silent past its expected time plus the grace period
 *   is down; the next successful ping resolves the incident.
 */
class HeartbeatMonitor
{
    public const BODY_LIMIT = 2000;

    public const KEEP_PINGS = 500;

    public function __construct(private IncidentManager $incidents) {}

    public function ping(Heartbeat $heartbeat, string $kind, ?int $exitCode = null, ?string $body = null): HeartbeatPing
    {
        if ($exitCode !== null && $kind !== 'start') {
            $kind = $exitCode === 0 ? 'success' : 'fail';
        }

        $now = CarbonImmutable::now();
        $body = $body !== null && trim($body) !== '' ? mb_substr((string) LogFingerprint::redact(trim($body)), 0, self::BODY_LIMIT) : null;

        return Tenant::run($heartbeat->team_id, function () use ($heartbeat, $kind, $exitCode, $body, $now) {
            $duration = $kind !== 'start' && $heartbeat->last_start_at && $heartbeat->last_start_at->gte($heartbeat->last_ping_at ?? $heartbeat->last_start_at)
                ? (int) min(PHP_INT_MAX, $heartbeat->last_start_at->diffInMilliseconds($now, true))
                : null;

            $ping = DB::transaction(function () use ($heartbeat, $kind, $exitCode, $body, $now, $duration) {
                $ping = $heartbeat->pings()->create(['kind' => $kind, 'exit_code' => $exitCode, 'duration_ms' => $duration, 'body' => $body, 'received_at' => $now]);

                if ($kind === 'start') {
                    $heartbeat->forceFill(['last_start_at' => $now])->save();

                    return $ping;
                }

                $heartbeat->forceFill([
                    'last_ping_at' => $now,
                    'last_duration_ms' => $duration,
                    // A paused job keeps its pings but is not judged.
                    'status' => $heartbeat->isPaused() ? 'paused' : ($kind === 'success' ? 'up' : 'down'),
                    'next_due_at' => $heartbeat->isPaused() ? null : $heartbeat->dueAfter($now),
                ])->save();

                return $ping;
            });

            if ($kind === 'success' && ! $heartbeat->isPaused()) {
                $this->incidents->recover($heartbeat->fingerprint(), __('heartbeats.incident.recovered', [], 'nl'));
            } elseif ($kind === 'fail' && ! $heartbeat->isPaused()) {
                $this->open($heartbeat, 'failed', $now, ['exit_code' => $exitCode, 'output' => $body]);
            }

            $this->prune($heartbeat);

            return $ping;
        });
    }

    /**
     * Opens incidents for jobs that are past their expected time plus the
     * grace period. Runs every minute.
     *
     * @return int the number of jobs found late
     */
    public function check(): int
    {
        $late = 0;

        Heartbeat::query()->withoutGlobalScopes()
            // Demo jobs are driven by serverscope:demo:tick, never judged here.
            ->whereNotIn('team_id', \App\Support\Demo::teamIds())
            ->whereIn('status', ['up', 'down'])
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($heartbeats) use (&$late) {
                foreach ($heartbeats as $heartbeat) {
                    $lateAt = $heartbeat->lateAt();

                    if ($lateAt === null || $lateAt->isFuture()) {
                        continue;
                    }

                    $late++;
                    Tenant::run($heartbeat->team_id, function () use ($heartbeat) {
                        $heartbeat->forceFill(['status' => 'down'])->save();
                        $this->open($heartbeat, 'late', CarbonImmutable::instance($heartbeat->next_due_at), []);
                    });
                }
            });

        return $late;
    }

    public function pause(Heartbeat $heartbeat): void
    {
        $heartbeat->forceFill(['status' => 'paused', 'paused_at' => now(), 'next_due_at' => null])->save();
        $this->incidents->recover($heartbeat->fingerprint(), __('heartbeats.incident.paused', [], 'nl'), notify: false);
    }

    public function resume(Heartbeat $heartbeat): void
    {
        // Judged again from the next ping on, so a long pause does not alert at once.
        $heartbeat->forceFill(['status' => 'new', 'paused_at' => null, 'next_due_at' => null])->save();
    }

    /**
     * Expected time after a schedule change; a job that has not pinged yet stays new.
     */
    public function reschedule(Heartbeat $heartbeat): void
    {
        if ($heartbeat->last_ping_at && in_array($heartbeat->status, ['up', 'down'], true)) {
            $heartbeat->forceFill(['next_due_at' => $heartbeat->dueAfter($heartbeat->last_ping_at)])->save();
        }
    }

    /** @param  array<string, mixed>  $context */
    private function open(Heartbeat $heartbeat, string $reason, CarbonImmutable $startedAt, array $context): void
    {
        $exit = $context['exit_code'] ?? null;
        $description = match ($reason) {
            'late' => __('heartbeats.incident.late_text', ['schedule' => $heartbeat->scheduleLabel(), 'grace' => $heartbeat->grace_minutes, 'last' => $heartbeat->last_ping_at?->toDateTimeString() ?? '—'], 'nl'),
            default => __($exit !== null ? 'heartbeats.incident.failed_exit_text' : 'heartbeats.incident.failed_text', ['code' => $exit], 'nl')
                .(! empty($context['output']) ? "\n\n".$context['output'] : ''),
        };

        $this->incidents->trigger(new Signal(
            fingerprint: $heartbeat->fingerprint(),
            source: 'heartbeat',
            severity: $heartbeat->severity,
            title: __('heartbeats.incident.'.$reason.'_title', ['name' => $heartbeat->name], 'nl'),
            description: $description,
            subject: $heartbeat,
            context: ['heartbeat_id' => $heartbeat->id, 'reason' => $reason, ...array_filter(['exit_code' => $exit])],
            startedAt: $startedAt,
        ));
    }

    private function prune(Heartbeat $heartbeat): void
    {
        // Only now and then: keeps the newest pings per job.
        if (random_int(1, 20) !== 1) {
            return;
        }

        $cutoff = $heartbeat->pings()->orderByDesc('id')->skip(self::KEEP_PINGS)->value('id');

        if ($cutoff !== null) {
            $heartbeat->pings()->where('id', '<=', $cutoff)->delete();
        }
    }
}
