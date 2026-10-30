<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Label;
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
            self::OPENED => $this->incident->severity === 'critical' ? __('messages.incident.prefix.opened_critical') : __('messages.incident.prefix.opened'),
            self::REOPENED => __('messages.incident.prefix.reopened'),
            self::RESOLVED => __('messages.incident.prefix.resolved'),
            self::ESCALATED => __('messages.incident.prefix.escalated'),
            self::SEVERITY_INCREASED => __('messages.incident.prefix.severity_increased'),
            self::UNACKNOWLEDGED => __('messages.incident.prefix.unacknowledged'),
            default => __('messages.incident.prefix.opened'),
        };

        return __('messages.incident.subject', ['prefix' => $prefix, 'title' => $this->incident->title]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $incident = $this->incident;
        $severity = Label::of('severity', $incident->severity, \App\Support\Severity::LABELS);
        $source = Label::of('incident_source', $incident->source, Incident::SOURCE_LABELS);

        $message = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting(match ($this->kind) {
                self::RESOLVED => __('messages.incident.greeting.resolved'),
                self::REOPENED => __('messages.incident.greeting.reopened'),
                self::ESCALATED => __('messages.incident.greeting.escalated'),
                self::SEVERITY_INCREASED => __('messages.incident.greeting.severity_increased'),
                self::UNACKNOWLEDGED => __('messages.incident.greeting.unacknowledged'),
                default => __('messages.incident.greeting.opened'),
            })
            ->line($incident->title)
            ->line(__('messages.incident.facts', ['severity' => $severity, 'source' => $source, 'subject' => $incident->subjectName()]));

        if ($incident->description) {
            $message->line($incident->description);
        }

        $message->line(__('messages.incident.opened_at', ['date' => Time::dateTimeZone($incident->opened_at)]));

        if ($this->kind === self::RESOLVED && $incident->resolved_at) {
            $message->line(__('messages.incident.resolved_at', ['date' => Time::dateTimeZone($incident->resolved_at)]));
        }

        if ($this->kind !== self::RESOLVED && $incident->severity === 'critical') {
            $message->error();
        }

        return $message->action(__('messages.incident.action'), route('incidents.show', $incident));
    }

    public function failed(\Throwable $exception): void
    {
        NotificationDispatcher::markFailed($this->id, $exception->getMessage());
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
