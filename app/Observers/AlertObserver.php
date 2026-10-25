<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertStateChanged;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies administrators on alert state transitions only.
 *
 * Repeated evaluations of an already active alert only update its
 * observed value and never notify again. Alerts closed because their
 * rule was disabled ('disabled') are not announced as recoveries.
 */
class AlertObserver
{
    public function created(Alert $alert): void
    {
        if ($alert->status === 'active') {
            $this->notify($alert, AlertStateChanged::TRIGGERED);
        }
    }

    public function updated(Alert $alert): void
    {
        if (
            $alert->wasChanged('status')
            && $alert->getOriginal('status') === 'active'
            && $alert->status === 'resolved'
        ) {
            $this->notify($alert, AlertStateChanged::RESOLVED);
        }
    }

    private function notify(Alert $alert, string $transition): void
    {
        if (! config('serverscope.alert_mail_enabled')) {
            return;
        }

        $recipients = User::query()
            ->where('is_admin', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new AlertStateChanged($alert, $transition),
        );
    }
}
