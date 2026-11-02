<?php

namespace App\Services\Intelligence;

use App\Jobs\RunInvestigation;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Requests NEXUS Intelligence investigations: from incident lifecycle
 * events (automatic, for the root incident of a group, after a delay so
 * grouping and the next samples are in) and by hand. Available on every
 * plan: it uses no AI and costs nothing per run.
 *
 * Duplicates are avoided: while a run of an incident is queued or running,
 * further requests return that run (a burst of events becomes one run).
 * A per-team hourly cap and a short manual cooldown bound the work.
 */
class Investigations
{
    /** Returns the queued (or still pending) investigation, or a reason: cooldown, limit. */
    public function request(Incident $incident, string $trigger, ?User $user = null): Investigation|string
    {
        // Automatic runs look at the whole group from its root.
        if ($trigger !== 'manual' && $incident->parent_id !== null) {
            $root = Incident::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->find($incident->parent_id);
            $incident = $root ?? $incident;
        }

        $teamId = (int) $incident->team_id;
        $runs = fn () => Investigation::query()->withoutGlobalScopes()->where('team_id', $teamId);

        return DB::transaction(function () use ($incident, $trigger, $user, $teamId, $runs) {
            $pending = $runs()->where('incident_id', $incident->id)->whereIn('status', Investigation::PENDING)->lockForUpdate()->latest('id')->first();

            if ($pending !== null) {
                return $pending;
            }

            if ($trigger === 'manual') {
                $cooldown = (int) config('serverscope.intelligence.manual_cooldown_seconds', 60);

                if ($cooldown > 0 && $runs()->where('incident_id', $incident->id)->where('trigger', 'manual')->where('created_at', '>', now()->subSeconds($cooldown))->exists()) {
                    return 'cooldown';
                }
            }

            if ($runs()->where('created_at', '>', now()->subHour())->count() >= (int) config('serverscope.intelligence.max_runs_per_team_per_hour', 60)) {
                return 'limit';
            }

            $investigation = (new Investigation)->forceFill([
                'team_id' => $teamId,
                'incident_id' => $incident->id,
                'status' => 'queued',
                'trigger' => $trigger,
                'requested_by' => $user?->id,
                'pipeline_version' => InvestigationRunner::PIPELINE_VERSION,
            ]);
            $investigation->save();

            $delay = $trigger === 'manual' ? 0 : max(0, (int) config('serverscope.intelligence.auto_delay_seconds', 120));
            DB::afterCommit(fn () => RunInvestigation::dispatch($investigation->id)->delay(now()->addSeconds($delay)));

            return $investigation;
        });
    }

    /** The latest run of an incident (any status), within its team. */
    public static function latest(Incident $incident): ?Investigation
    {
        return Investigation::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->where('incident_id', $incident->id)->latest('id')->first();
    }

    /** The latest run with a result. */
    public static function latestFinished(Incident $incident): ?Investigation
    {
        return Investigation::query()->withoutGlobalScopes()->where('team_id', $incident->team_id)->where('incident_id', $incident->id)->finished()->latest('id')->first();
    }
}
