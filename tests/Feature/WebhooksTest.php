<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Net\SafeHttpClient;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private FakeResolver $dns;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->dns = (new FakeResolver)->host('hooks.example.com', '93.184.215.50');
        $this->app->instance(Resolver::class, $this->dns);
        $this->admin = User::factory()->create(['is_admin' => true]);
        Http::preventStrayRequests();
    }

    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create([
            'name' => 'Hook', 'url' => 'https://hooks.example.com/in', 'secret' => 'whsec_test',
            'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true, ...$attributes,
        ]);
    }

    public function test_admin_creates_webhook_with_validated_url_and_one_time_secret(): void
    {
        $this->dns->host('internal.example.com', '10.0.0.5');
        $viewer = User::factory()->create();

        $payload = ['name' => 'n8n', 'url' => 'https://hooks.example.com/in', 'events' => ['incident.opened'], 'is_active' => '1'];

        $this->actingAs($viewer)->post(route('webhooks.store'), $payload)->assertForbidden();

        foreach (['https://internal.example.com/x' => 'niet toegestaan', 'https://127.0.0.1/x' => 'niet toegestaan', 'https://hooks.example.com:6379/' => 'niet toegestaan', 'http://hooks.example.com/in' => 'https'] as $url => $message) {
            $this->actingAs($this->admin)->post(route('webhooks.store'), [...$payload, 'url' => $url])->assertSessionHasErrors('url');
        }
        $this->actingAs($this->admin)->post(route('webhooks.store'), [...$payload, 'events' => ['incident.deleted']])->assertSessionHasErrors('events.0');

        $response = $this->actingAs($this->admin)->post(route('webhooks.store'), $payload);
        $endpoint = WebhookEndpoint::query()->sole();
        $response->assertRedirect(route('webhooks.show', $endpoint));

        $secret = $response->getSession()->get('webhook_secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $endpoint->secret);
        $this->assertStringNotContainsString($secret, DB::table('webhook_endpoints')->value('secret'));
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.created']);
    }

    public function test_incident_events_are_delivered_with_a_verifiable_signature(): void
    {
        $this->endpoint(['events' => ['incident.opened', 'incident.resolved']]);
        Http::fake(['https://hooks.example.com/*' => Http::response(['ok' => true], 200)]);

        $incident = app(IncidentManager::class)->trigger(new Signal('wh:1', 'manual', 'critical', 'Storing', 'x'));

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('success', $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame(1, $delivery->attempts);

        Http::assertSent(function (Request $request) use ($incident, $delivery) {
            $timestamp = (int) $request->header('X-ServerScope-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_test');
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->header('X-ServerScope-Event')[0] === 'incident.opened'
                && $request->header('X-ServerScope-Delivery')[0] === $delivery->uuid
                && hash_equals($expected, $request->header('X-ServerScope-Signature')[0])
                && $body['data']['incident']['id'] === $incident->id
                && $body['event'] === 'incident.opened';
        });

        // Subscribed events only.
        app(IncidentManager::class)->trigger(new Signal('wh:2', 'manual', 'warning', 'x'));
        $this->assertSame(2, WebhookDelivery::query()->count());
        app(IncidentManager::class)->recover('wh:1', 'ok');
        $this->assertSame(3, WebhookDelivery::query()->count());
    }

    public function test_inactive_and_unsubscribed_endpoints_receive_nothing(): void
    {
        Queue::fake();
        $this->endpoint(['is_active' => false]);
        $this->endpoint(['events' => ['dns.changed']]);

        app(IncidentManager::class)->trigger(new Signal('wh:3', 'manual', 'warning', 'x'));

        $this->assertSame(0, WebhookDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_retry_rules(): void
    {
        $endpoint = $this->endpoint();

        foreach ([500 => 'retrying', 429 => 'retrying', 400 => 'failed', 301 => 'failed'] as $status => $expected) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response('nee', $status, ['Location' => 'https://elders.example/'])]);
            $delivery = $endpoint->deliveries()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'event' => 'test', 'payload' => ['x' => 1]]);
            // Retries are released back onto the queue, never thrown into
            // the request that triggered the event.
            (new DeliverWebhook($delivery->id))->handle(app(SafeHttpClient::class));

            $this->assertSame($expected, $delivery->fresh()->status, "HTTP {$status}");
            Http::assertSentCount(1);
        }
    }

    public function test_destination_is_revalidated_at_delivery_time(): void
    {
        $endpoint = $this->endpoint();
        // DNS now points at an internal address (rebinding after save).
        $this->dns->host('hooks.example.com', '169.254.169.254');
        Http::fake();

        $delivery = $endpoint->deliveries()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'event' => 'test', 'payload' => []]);
        (new DeliverWebhook($delivery->id))->handle(app(SafeHttpClient::class));

        Http::assertNothingSent();
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('intern', $delivery->fresh()->error);
    }

    public function test_endpoint_is_disabled_after_repeated_failures(): void
    {
        $endpoint = $this->endpoint(['events' => ['incident.opened']]);
        $endpoint->forceFill(['consecutive_failures' => DeliverWebhook::DISABLE_AFTER_FAILURES - 1])->save();
        Http::fake(['*' => Http::response('', 404)]);

        app(IncidentManager::class)->trigger(new Signal('wh:4', 'manual', 'warning', 'x'));

        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.auto_disabled']);
    }

    public function test_test_delivery_redelivery_and_secret_rotation(): void
    {
        $endpoint = $this->endpoint();
        Http::fake(['*' => Http::response('', 503)]);

        $this->actingAs($this->admin)->post(route('webhooks.test', $endpoint))->assertSessionHas('success');
        $failed = WebhookDelivery::query()->sole();
        $this->assertSame('failed', $failed->status, 'sync queue: retries exhausted immediately');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response('', 204)]);
        $this->actingAs($this->admin)->post(route('webhooks.redeliver', [$endpoint, $failed]))->assertSessionHas('success');
        $this->assertSame('success', WebhookDelivery::query()->latest('id')->first()->status);

        $other = $this->endpoint();
        $this->actingAs($this->admin)->post(route('webhooks.redeliver', [$other, $failed]))->assertNotFound();

        $old = $endpoint->secret;
        $this->actingAs($this->admin)->post(route('webhooks.secret', $endpoint))->assertSessionHas('webhook_secret');
        $this->assertNotSame($old, $endpoint->fresh()->secret);

        // Shown once right after rotation, never again.
        $this->actingAs($this->admin)->get(route('webhooks.show', $endpoint))->assertOk()->assertSee($endpoint->fresh()->secret);
        $this->actingAs($this->admin)->get(route('webhooks.show', $endpoint))->assertOk()->assertSee('Geleverd')->assertDontSee($endpoint->fresh()->secret);
    }
}
