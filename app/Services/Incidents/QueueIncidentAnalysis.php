<?php

namespace App\Services\Incidents;

use App\Events\IncidentOpened;
use App\Jobs\QueueAutomaticIncidentAnalysis;
use App\Services\Intelligence\Investigations;
use Throwable;

/**
 * IncidentOpened listener: queues an automatic analysis for new root
 * incidents of at least serverscope.ai.auto_min_severity, after a delay so
 * confirmations and recent samples are in. Does nothing without AI
 * configuration and never throws into incident handling. Incidents that
 * get an automatic NEXUS Intelligence investigation are analysed when
 * that run ends instead (one automatic analysis either way).
 */
class QueueIncidentAnalysis
{
    public function __construct(private IncidentInvestigator $investigator) {}

    public function handle(IncidentOpened $event): void
    {
        try {
            $incident = $event->incident;

            // With an automatic investigation, the analysis follows when that
            // run ends (IncidentInvestigator::afterInvestigation), from its result.
            if (Investigations::automaticFor($incident, 'opened') || ! $this->investigator->autoEligible($incident)) {
                return;
            }

            QueueAutomaticIncidentAnalysis::dispatch($incident->id)->delay(now()->addSeconds(max(0, (int) config('serverscope.ai.auto_delay_seconds', 120))));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
