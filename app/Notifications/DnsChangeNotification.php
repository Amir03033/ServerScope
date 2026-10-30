<?php

namespace App\Notifications;

use App\Models\DnsChange;
use App\Models\Domain;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class DnsChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /** @var array<int, array{type: string, added: array, removed: array}> */
    public array $summary;

    /**
     * @param  array<int, DnsChange>  $changes
     */
    public function __construct(
        public Domain $domain,
        array $changes,
        public array $channels = ['mail'],
    ) {
        $this->summary = array_map(fn (DnsChange $c) => [
            'type' => $c->record_type,
            'added' => array_slice($c->added, 0, 10),
            'removed' => array_slice($c->removed, 0, 10),
        ], $changes);

        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function subjectLine(): string
    {
        $types = implode(', ', array_column($this->summary, 'type'));

        return __('messages.dns.subject', ['domain' => $this->domain->displayName(), 'types' => $types]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting(__('messages.dns.greeting'))
            ->line(__('messages.dns.intro', ['domain' => $this->domain->displayName()]));

        foreach ($this->summary as $change) {
            $message->line("{$change['type']}:".(in_array($change['type'], ['NS'], true) ? __('messages.dns.nameservers') : ''));

            foreach ($change['added'] as $value) {
                $message->line('+ '.mb_substr($value, 0, 200));
            }

            foreach ($change['removed'] as $value) {
                $message->line('− '.mb_substr($value, 0, 200));
            }
        }

        return $message
            ->line(__('messages.dns.check'))
            ->action(__('messages.dns.action'), route('domains.show', $this->domain));
    }

    public function failed(Throwable $exception): void
    {
        NotificationDispatcher::markFailed($this->id, $exception->getMessage());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'dns_changed',
            'domain_id' => $this->domain->id,
            'message' => __('messages.dns.in_app', ['domain' => $this->domain->displayName(), 'types' => implode(', ', array_column($this->summary, 'type'))]),
            'url' => route('domains.show', $this->domain, false),
            'severity' => 'info',
        ];
    }
}
