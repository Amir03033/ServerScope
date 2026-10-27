<?php

namespace App\Services\Notifications;

use App\Http\Resources\IncidentResource;
use App\Jobs\DeliverWebhook;
use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function incident(Incident $incident, string $event): void
    {
        $this->dispatch($event, ['incident' => (new IncidentResource($incident->loadMissing('subject')))->resolve()], $incident);
    }

    /**
     * @param  array<int, DnsChange>  $changes
     */
    public function dnsChange(Domain $domain, array $changes): void
    {
        $this->dispatch('dns.changed', [
            'domain' => ['id' => $domain->id, 'name' => $domain->name, 'display_name' => $domain->displayName()],
            'changes' => array_map(fn ($c) => ['type' => $c->record_type, 'added' => $c->added, 'removed' => $c->removed, 'detected_at' => $c->detected_at->toIso8601String()], $changes),
        ]);
    }

    public function pageChange(MonitoredPage $page, PageChange $change): void
    {
        $this->dispatch('page.changed', [
            'page' => ['id' => $page->id, 'url' => $page->url, 'domain_id' => $page->domain_id],
            'change' => ['id' => $change->id, 'kind' => $change->kind, 'significance' => $change->significance, 'summary' => $change->summary, 'detected_at' => $change->detected_at->toIso8601String()],
        ]);
    }

    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->queue($endpoint, 'test', ['message' => 'Testlevering vanuit ServerScope.']);
    }

    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        return $this->queue($delivery->endpoint, $delivery->event, $delivery->payload['data'] ?? []);
    }

    private function dispatch(string $event, array $data, ?Incident $incident = null): void
    {
        WebhookEndpoint::query()->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribedTo($event))
            ->filter(fn (WebhookEndpoint $endpoint) => $incident === null || $endpoint->wantsSeverity($incident->severity))
            // Chat channels follow the same incident-aware suppression as
            // e-mail; machine endpoints receive everything (with parent_id).
            ->reject(fn (WebhookEndpoint $endpoint) => $endpoint->isDiscord() && $incident !== null && app(NotificationDispatcher::class)->suppressionReason($incident, $event) !== null)
            ->reject(fn (WebhookEndpoint $endpoint) => $endpoint->isDiscord() && $this->isDuplicate($endpoint, $event, $incident))
            ->each(fn (WebhookEndpoint $endpoint) => $this->queue($endpoint, $event, $data));
    }

    /**
     * Chat channels are read by people: the same incident event in the
     * same state is posted at most once per cooldown (flapping, several
     * escalation triggers for one incident). Machine endpoints keep
     * receiving every event.
     */
    private function isDuplicate(WebhookEndpoint $endpoint, string $event, ?Incident $incident): bool
    {
        $minutes = (int) config('serverscope.notifications.chat_cooldown_minutes', 15);

        if ($incident === null || $minutes <= 0) {
            return false;
        }

        $key = implode(':', ['webhook-chat', $endpoint->id, $event, $incident->id, $incident->status, $incident->severity]);

        return ! Cache::add($key, true, now()->addMinutes($minutes));
    }

    private function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $uuid = (string) Str::uuid();

        $delivery = $endpoint->deliveries()->create([
            'uuid' => $uuid,
            'event' => $event,
            'payload' => ['id' => $uuid, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data],
            'status' => 'pending',
        ]);

        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
