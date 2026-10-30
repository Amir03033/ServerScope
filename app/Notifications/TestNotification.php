<?php

namespace App\Notifications;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public array $channels = ['mail']) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function subjectLine(): string
    {
        return __('messages.test.subject');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine())
            ->line(__('messages.test.line'));
    }

    public function failed(Throwable $exception): void
    {
        NotificationDispatcher::markFailed($this->id, $exception->getMessage());
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'test', 'message' => __('messages.test.in_app'), 'url' => null, 'severity' => 'info'];
    }
}
