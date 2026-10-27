<?php

namespace App\Notifications;

use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/** A significant content change of a monitored page (opt-in per page). */
class PageChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public MonitoredPage $page,
        public PageChange $change,
        public array $channels = ['mail'],
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function subjectLine(): string
    {
        return "[ServerScope] Inhoud gewijzigd: {$this->page->url}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting('De inhoud van een pagina is gewijzigd')
            ->line($this->page->url)
            ->line((string) $this->change->summary);

        foreach (array_slice($this->change->diff['added'] ?? [], 0, 5) as $line) {
            $message->line('+ '.mb_substr($line, 0, 200));
        }

        foreach (array_slice($this->change->diff['removed'] ?? [], 0, 5) as $line) {
            $message->line('− '.mb_substr($line, 0, 200));
        }

        return $message
            ->line('Is dit een verwachte wijziging? Bevestig hem dan op de paginadetails.')
            ->action('Pagina bekijken', route('pages.show', $this->page));
    }

    public function failed(Throwable $exception): void
    {
        NotificationDispatcher::markFailed($this->id, $exception->getMessage());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'page_changed',
            'page_id' => $this->page->id,
            'message' => 'Inhoud gewijzigd: '.$this->page->url,
            'url' => route('pages.show', $this->page, false),
            'severity' => 'info',
        ];
    }
}
