<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an address that already has an account when someone tries to
 * register with it. The visitor sees the same answer as for a new
 * address, so registration does not reveal which addresses exist.
 */
class RegistrationAttemptNotice extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.mail.attempt.subject'))
            ->line(__('auth.mail.attempt.line1'))
            ->action(__('auth.mail.attempt.action'), route('password.request'))
            ->line(__('auth.mail.attempt.line2'));
    }
}
