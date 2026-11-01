<?php

namespace App\Services\Incidents;

use App\Events\IncidentOpened;
use App\Jobs\QueueAutomaticIncidentAnalysis;
use App\Services\Ai\AiProvider;
use App\Support\Severity;
use Throwable;

/**
 * IncidentOpened listener: queues an automatic analysis for new root
 * incidents of at least serverscope.ai.auto_min_severity, after a delay so
 * confirmations and recent samples are in. Does nothing without AI
 * configuration and never throws into incident handling.
 */
class QueueIncidentAnalysis
{
    public function __construct(private AiProvider $provider) {}

    public function handle(IncidentOpened $event): void
    {
        try {
            $min = config('serverscope.ai.auto_min_severity');
            $incident = $event->incident;

            if (! $this->provider->available() || $min === null || $min === '' || $incident->parent_id !== null
                || Severity::rank($incident->severity) < Severity::rank((string) $min)) {
                return;
            }

            QueueAutomaticIncidentAnalysis::dispatch($incident->id)->delay(now()->addSeconds(max(0, (int) config('serverscope.ai.auto_delay_seconds', 120))));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
