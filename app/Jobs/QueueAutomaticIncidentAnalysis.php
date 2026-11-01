<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\Incidents\IncidentInvestigator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Builds the evidence for a new incident in the background and queues its analysis. */
class QueueAutomaticIncidentAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $incidentId) {}

    public function handle(IncidentInvestigator $investigator): void
    {
        $incident = Incident::query()->withoutGlobalScopes()->find($this->incidentId);

        if ($incident === null) {
            return;
        }

        try {
            $investigator->request($incident, 'auto');
        } catch (Throwable $exception) {
            // Never affects monitoring; the analysis can be requested by hand.
            report($exception);
        }
    }
}
