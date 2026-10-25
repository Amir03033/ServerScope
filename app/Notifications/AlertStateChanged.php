<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\AlertRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertStateChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public const TRIGGERED = 'triggered';

    public const RESOLVED = 'resolved';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public Alert $alert,
        public string $transition,
    ) {
        // Only send once the alert transition has been committed.
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alert = $this->alert->loadMissing(['server', 'alertRule']);
        $isTriggered = $this->transition === self::TRIGGERED;

        $serverName = $alert->server?->name ?? 'Onbekende server';
        $ruleName = $alert->alertRule?->name ?? 'Verwijderde alertregel';
        $unit = AlertRule::metricUnit($alert->metric);
        $metric = AlertRule::metricLabel($alert->metric)
            .($alert->dimension !== null ? " ({$alert->dimension})" : '');

        $message = (new MailMessage)
            ->subject(sprintf(
                '[ServerScope] %s: %s op %s',
                $isTriggered ? 'Alert' : 'Hersteld',
                $ruleName,
                $serverName,
            ))
            ->greeting($isTriggered ? 'Er is een alert geactiveerd' : 'Een alert is opgelost')
            ->line("Server: {$serverName}")
            ->line("Alertregel: {$ruleName}")
            ->line("Metric: {$metric}")
            ->line(sprintf(
                'Waargenomen waarde: %s%s (drempel %s%s)',
                number_format($alert->observed_value, 1, ',', '.'),
                $unit,
                number_format($alert->threshold, 1, ',', '.'),
                $unit,
            ))
            ->line('Geactiveerd: '.$alert->triggered_at?->format('d-m-Y H:i:s T'));

        if (! $isTriggered && $alert->resolved_at !== null) {
            $message->line('Opgelost: '.$alert->resolved_at->format('d-m-Y H:i:s T'));
        }

        if ($isTriggered) {
            $message->error();
        }

        return $message->action('Alerts bekijken', route('alerts.index'));
    }
}
