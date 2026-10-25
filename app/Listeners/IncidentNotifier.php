<?php

namespace App\Listeners;

use App\Events\IncidentEscalated;
use App\Events\IncidentOpened;
use App\Events\IncidentReopened;
use App\Events\IncidentResolved;
use App\Events\IncidentSeverityIncreased;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\IncidentNotification;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Sends notifications for incident lifecycle events only, never for
 * individual failed checks.
 */
class IncidentNotifier
{
    public function opened(IncidentOpened $event): void
    {
        $this->send($event->incident, IncidentNotification::OPENED);
    }

    public function reopened(IncidentReopened $event): void
    {
        $this->send($event->incident, IncidentNotification::REOPENED);
    }

    public function resolved(IncidentResolved $event): void
    {
        $this->send($event->incident, IncidentNotification::RESOLVED);
    }

    public function escalated(IncidentEscalated $event): void
    {
        $this->send($event->incident, IncidentNotification::ESCALATED);
    }

    public function severityIncreased(IncidentSeverityIncreased $event): void
    {
        $this->send($event->incident, IncidentNotification::SEVERITY_INCREASED);
    }

    private function send(Incident $incident, string $kind): void
    {
        if (! config('serverscope.alert_mail_enabled')) {
            return;
        }

        $recipients = User::query()->where('is_admin', true)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentNotification($incident, $kind));
            $incident->forceFill(['last_notified_at' => now()])->saveQuietly();
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            IncidentOpened::class => 'opened',
            IncidentReopened::class => 'reopened',
            IncidentResolved::class => 'resolved',
            IncidentEscalated::class => 'escalated',
            IncidentSeverityIncreased::class => 'severityIncreased',
        ];
    }
}
