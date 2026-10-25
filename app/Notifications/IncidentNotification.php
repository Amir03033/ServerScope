<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Support\Time;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const OPENED = 'opened';

    public const REOPENED = 'reopened';

    public const RESOLVED = 'resolved';

    public const ESCALATED = 'escalated';

    public const SEVERITY_INCREASED = 'severity_increased';

    public const UNACKNOWLEDGED = 'unacknowledged';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public Incident $incident,
        public string $kind,
        public array $channels = ['mail'],
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function subjectLine(): string
    {
        $prefix = match ($this->kind) {
            self::OPENED => $this->incident->severity === 'critical' ? 'Kritiek incident' : 'Incident',
            self::REOPENED => 'Heropend',
            self::RESOLVED => 'Opgelost',
            self::ESCALATED => 'Escalatie',
            self::SEVERITY_INCREASED => 'Ernst verhoogd',
            self::UNACKNOWLEDGED => 'Niet bevestigd',
            default => 'Incident',
        };

        return "[ServerScope] {$prefix}: {$this->incident->title}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->incident;
        $severity = ['info' => 'Info', 'warning' => 'Waarschuwing', 'critical' => 'Kritiek'][$incident->severity] ?? $incident->severity;

        $message = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting(match ($this->kind) {
                self::RESOLVED => 'Het incident is opgelost',
                self::REOPENED => 'Het incident is opnieuw opgetreden',
                self::ESCALATED => 'Een incident vraagt aandacht',
                self::SEVERITY_INCREASED => 'De ernst van een incident is verhoogd',
                self::UNACKNOWLEDGED => 'Dit incident is nog niet bevestigd',
                default => 'Er is een nieuw incident',
            })
            ->line($incident->title)
            ->line("Ernst: {$severity} · Bron: {$incident->sourceLabel()} · Betreft: {$incident->subjectName()}");

        if ($incident->description) {
            $message->line($incident->description);
        }

        $message->line('Geopend: '.Time::format($incident->opened_at, 'd-m-Y H:i T'));

        if ($this->kind === self::RESOLVED && $incident->resolved_at) {
            $message->line('Opgelost: '.Time::format($incident->resolved_at, 'd-m-Y H:i T'));
        }

        if ($this->kind !== self::RESOLVED && $incident->severity === 'critical') {
            $message->error();
        }

        return $message->action('Incident bekijken', route('incidents.show', $incident));
    }

    public function failed(\Throwable $exception): void
    {
        \App\Services\Notifications\NotificationDispatcher::markFailed($this->id, $exception->getMessage());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'kind' => $this->kind,
            'title' => $this->incident->title,
            'severity' => $this->incident->severity,
            'status' => $this->incident->status,
            'subject' => $this->incident->subjectName(),
            'message' => $this->subjectLine(),
            'url' => route('incidents.show', $this->incident, false),
        ];
    }
}
