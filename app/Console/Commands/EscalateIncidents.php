<?php

namespace App\Console\Commands;

use App\Events\IncidentUnacknowledged;
use App\Models\Incident;
use App\Services\Incidents\IncidentManager;
use Illuminate\Console\Command;

/**
 * Escalation policy: an incident that is still open and unacknowledged
 * after the configured number of minutes for its severity is escalated
 * once (incident event + notification to users and escalation contacts).
 */
class EscalateIncidents extends Command
{
    protected $signature = 'serverscope:escalate-incidents';

    protected $description = 'Escalate incidents that were not acknowledged in time';

    public function handle(IncidentManager $manager): int
    {
        $escalated = 0;

        foreach (config('serverscope.escalation.after_minutes', []) as $severity => $minutes) {
            if ($minutes === null || (int) $minutes <= 0) {
                continue;
            }

            $incidents = Incident::query()
                ->where('status', 'open')
                ->whereNull('acknowledged_at')
                ->whereNull('escalated_at')
                ->where('severity', $severity)
                ->where('opened_at', '<=', now()->subMinutes((int) $minutes))
                ->get();

            foreach ($incidents as $incident) {
                // The root incident escalates for its group.
                if ($incident->isSuppressedByParent()) {
                    continue;
                }

                $claimed = Incident::query()->whereKey($incident->id)->whereNull('escalated_at')->update(['escalated_at' => now()]);

                if ($claimed !== 1) {
                    continue;
                }

                $manager->event($incident, 'escalated', null, "Niet bevestigd binnen {$minutes} minuten; escalatie verstuurd.");
                IncidentUnacknowledged::dispatch($incident->fresh());
                $escalated++;
            }
        }

        $this->info("Geëscaleerd: {$escalated}");

        return self::SUCCESS;
    }
}
