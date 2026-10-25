<?php

namespace App\Services\Notifications;

use App\Http\Resources\IncidentResource;
use App\Jobs\DeliverWebhook;
use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function incident(Incident $incident, string $event): void
    {
        $this->dispatch($event, ['incident' => (new IncidentResource($incident->loadMissing('subject')))->resolve()]);
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

    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->queue($endpoint, 'test', ['message' => 'Testlevering vanuit ServerScope.']);
    }

    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        return $this->queue($delivery->endpoint, $delivery->event, $delivery->payload['data'] ?? []);
    }

    private function dispatch(string $event, array $data): void
    {
        WebhookEndpoint::query()->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribedTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => $this->queue($endpoint, $event, $data));
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
