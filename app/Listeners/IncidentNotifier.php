<?php

namespace App\Listeners;

use App\Events\DnsRecordsChanged;
use App\Events\IncidentEscalated;
use App\Events\IncidentOpened;
use App\Events\IncidentReopened;
use App\Events\IncidentResolved;
use App\Events\IncidentSeverityIncreased;
use App\Events\IncidentUnacknowledged;
use App\Notifications\IncidentNotification;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Sends notifications for incident lifecycle events only, never for
 * individual failed checks, and tracks their delivery.
 */
class IncidentNotifier
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {}

    public function opened(IncidentOpened $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::OPENED);
    }

    public function reopened(IncidentReopened $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::REOPENED);
    }

    public function resolved(IncidentResolved $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::RESOLVED);
    }

    public function escalated(IncidentEscalated $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::ESCALATED);
    }

    public function severityIncreased(IncidentSeverityIncreased $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::SEVERITY_INCREASED);
    }

    public function unacknowledged(IncidentUnacknowledged $event): void
    {
        $this->dispatcher->incident($event->incident, IncidentNotification::UNACKNOWLEDGED);
    }

    public function dnsChanged(DnsRecordsChanged $event): void
    {
        $this->dispatcher->dnsChange($event->domain, $event->changes);
    }

    public function sent(NotificationSent $event): void
    {
        NotificationDispatcher::markSent($event->notification->id ?? null, $event->channel, $event->notifiable);
    }

    public function failed(NotificationFailed $event): void
    {
        NotificationDispatcher::markFailed($event->notification->id ?? null, (string) ($event->data['message'] ?? 'Verzenden mislukt'), $event->channel);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            IncidentOpened::class => 'opened',
            IncidentReopened::class => 'reopened',
            IncidentResolved::class => 'resolved',
            IncidentEscalated::class => 'escalated',
            IncidentSeverityIncreased::class => 'severityIncreased',
            IncidentUnacknowledged::class => 'unacknowledged',
            DnsRecordsChanged::class => 'dnsChanged',
            NotificationSent::class => 'sent',
            NotificationFailed::class => 'failed',
        ];
    }
}
