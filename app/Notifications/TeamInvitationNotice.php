<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotice extends Notification
{
    public function __construct(private string $teamName, private string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('invitations.mail.subject', ['team' => $this->teamName]))
            ->line(__('invitations.mail.line1', ['team' => $this->teamName]))
            ->action(__('invitations.mail.action'), $this->url)
            ->line(__('invitations.mail.line2'));
    }
}
