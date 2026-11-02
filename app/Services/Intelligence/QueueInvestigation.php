<?php

namespace App\Services\Intelligence;

use App\Events\IncidentOpened;
use App\Events\IncidentReopened;
use App\Events\IncidentResolved;
use App\Models\Incident;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Incident lifecycle listener: queues an automatic investigation for the
 * configured events (serverscope.intelligence.auto_triggers). Never throws
 * into incident handling.
 */
class QueueInvestigation
{
    public function __construct(private Investigations $investigations) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            IncidentOpened::class => 'opened',
            IncidentReopened::class => 'reopened',
            IncidentResolved::class => 'resolved',
        ];
    }

    public function opened(IncidentOpened $event): void
    {
        $this->queue($event->incident, 'opened');
    }

    public function reopened(IncidentReopened $event): void
    {
        $this->queue($event->incident, 'reopened');
    }

    public function resolved(IncidentResolved $event): void
    {
        $this->queue($event->incident, 'resolved');
    }

    private function queue(Incident $incident, string $trigger): void
    {
        try {
            // An incident without a monitored resource (e.g. a manual one) has
            // nothing to investigate automatically; it can still be requested by hand.
            if ($incident->subject_type === null || ! in_array($trigger, (array) config('serverscope.intelligence.auto_triggers', []), true)) {
                return;
            }

            $this->investigations->request($incident, $trigger);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
