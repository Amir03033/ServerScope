<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Net\SafeHttpClient;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Notifications\DiscordMessage;
use App\Services\Notifications\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class DiscordWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'S3cretDiscordToken_abcdefghijklmnopqrstuvwxyz-0123456789ABCDEFGHIJKL';

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/'.self::TOKEN;

    private FakeResolver $dns;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->dns = (new FakeResolver)
            ->host('discord.com', '162.159.135.232')
            ->host('hooks.example.com', '93.184.215.50');
        $this->app->instance(Resolver::class, $this->dns);
        $this->admin = User::factory()->create(['is_admin' => true]);
        // Never talk to Discord from tests.
        Http::preventStrayRequests();
    }

    private function discord(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create([
            'name' => 'Ops-kanaal', 'type' => 'discord', 'url' => DiscordMessage::mask(self::URL), 'secret' => self::URL,
            'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true, ...$attributes,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return ['name' => 'Ops-kanaal', 'type' => 'discord', 'url' => self::URL, 'events' => ['incident.opened', 'incident.resolved'], 'is_active' => '1', ...$overrides];
    }

    public function test_only_administrators_manage_discord_webhooks(): void
    {
        $viewer = User::factory()->create();
        $endpoint = $this->discord();

        $this->post(route('webhooks.store'), $this->payload())->assertRedirect(route('login'));
        $this->actingAs($viewer)->post(route('webhooks.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->get(route('webhooks.create', ['type' => 'discord']))->assertForbidden();
        $this->actingAs($viewer)->get(route('webhooks.show', $endpoint))->assertForbidden();
        $this->actingAs($viewer)->put(route('webhooks.update', $endpoint), $this->payload(['url' => 'https://discord.com/api/webhooks/1111111111111111111/'.str_repeat('x', 68)]))->assertForbidden();
        $this->actingAs($viewer)->post(route('webhooks.test', $endpoint))->assertForbidden();
        $this->actingAs($viewer)->delete(route('webhooks.destroy', $endpoint))->assertForbidden();

        $this->assertSame(self::URL, $endpoint->fresh()->secret);
        $this->assertSame(1, WebhookEndpoint::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_only_discord_webhook_urls_are_accepted(): void
    {
        $rejected = [
            'https://hooks.example.com/api/webhooks/123456789012345678/'.self::TOKEN,
            'http://discord.com/api/webhooks/123456789012345678/'.self::TOKEN,
            'https://discord.com.evil.example/api/webhooks/123456789012345678/'.self::TOKEN,
            'https://discord.com/api/channels/123456789012345678/messages',
            'https://discord.com/api/webhooks/123456789012345678/'.self::TOKEN.'?wait=true',
            'https://user@discord.com/api/webhooks/123456789012345678/'.self::TOKEN,
            'https://discord.com/api/webhooks/abc/'.self::TOKEN,
        ];

        foreach ($rejected as $url) {
            $response = $this->actingAs($this->admin)->post(route('webhooks.store'), $this->payload(['url' => $url]));
            $response->assertSessionHasErrors('url');
            $this->assertStringNotContainsString(self::TOKEN, (string) session('errors')?->first('url'), $url);
        }

        $this->assertSame(0, WebhookEndpoint::query()->count());

        foreach (['https://discordapp.com/api/webhooks/123456789012345678/'.self::TOKEN, 'https://ptb.discord.com/api/v10/webhooks/123456789012345678/'.self::TOKEN.'/'] as $url) {
            $this->dns->host((string) parse_url($url, PHP_URL_HOST), '162.159.135.232');
            $this->actingAs($this->admin)->post(route('webhooks.store'), $this->payload(['url' => $url]))->assertSessionHasNoErrors();
        }

        $this->assertSame(2, WebhookEndpoint::query()->where('type', 'discord')->count());
    }

    public function test_webhook_url_is_stored_encrypted_and_never_shown(): void
    {
        $this->actingAs($this->admin)->get(route('webhooks.create', ['type' => 'discord']))->assertOk()
            ->assertSee('Discord-webhook-URL')->assertSee('name="type" value="discord"', false);

        $response = $this->actingAs($this->admin)->post(route('webhooks.store'), $this->payload());
        $endpoint = WebhookEndpoint::query()->sole();
        $response->assertRedirect(route('webhooks.show', $endpoint))->assertSessionMissing('webhook_secret');

        $this->assertSame('discord', $endpoint->type);
        $this->assertSame(self::URL, $endpoint->deliveryUrl());
        $this->assertSame('https://discord.com/api/webhooks/123456789012345678/••••••', $endpoint->url);

        // Nothing in the database holds the token in plain text.
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('webhook_endpoints')->get()));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('audit_logs')->get()));
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());

        foreach ([route('webhooks.index'), route('webhooks.show', $endpoint), route('webhooks.edit', $endpoint)] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk()->assertDontSee(self::TOKEN, false);
        }

        // Signing secrets do not apply; the URL is replaced via the form.
        $this->actingAs($this->admin)->post(route('webhooks.secret', $endpoint))->assertNotFound();
    }

    public function test_url_can_be_kept_or_replaced_when_editing(): void
    {
        $endpoint = $this->discord();

        $this->actingAs($this->admin)->put(route('webhooks.update', $endpoint), $this->payload(['url' => '', 'name' => 'Nieuw', 'min_severity' => 'critical']))->assertSessionHasNoErrors();
        $endpoint->refresh();
        $this->assertSame('Nieuw', $endpoint->name);
        $this->assertSame('critical', $endpoint->min_severity);
        $this->assertSame(self::URL, $endpoint->deliveryUrl());

        $endpoint->forceFill(['consecutive_failures' => 7])->save();
        $new = 'https://discord.com/api/webhooks/987654321098765432/'.str_repeat('N', 68);
        $this->actingAs($this->admin)->put(route('webhooks.update', $endpoint), $this->payload(['url' => $new]))->assertSessionHasNoErrors();
        $endpoint->refresh();

        $this->assertSame($new, $endpoint->deliveryUrl());
        $this->assertStringContainsString('987654321098765432', $endpoint->url);
        $this->assertSame(0, $endpoint->consecutive_failures);
        $this->assertSame('discord', $endpoint->type, 'type cannot be changed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.secret_rotated']);
    }

    public function test_server_offline_and_back_online_are_posted_as_embeds(): void
    {
        $this->discord();
        Http::fake(['https://discord.com/*' => Http::response('', 204)]);
        $server = Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test']);
        $manager = app(IncidentManager::class);

        $incident = $manager->trigger(new Signal('alert:1:'.$server->id.':agent_offline:-', 'agent', 'critical', 'web-01 reageert niet (agent offline)', 'Geen metingen ontvangen.', $server));
        $manager->recover('alert:1:'.$server->id.':agent_offline:-', 'Agent meldt zich weer.');

        $this->assertSame(['success', 'success'], WebhookDelivery::query()->orderBy('id')->pluck('status')->all());

        $sent = [];
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return true;
        });
        $this->assertCount(2, $sent);

        [$offline, $online] = array_map(fn (Request $r) => json_decode($r->body(), true), $sent);

        $this->assertSame(self::URL, $sent[0]->url());
        $this->assertFalse($sent[0]->hasHeader('X-ServerScope-Signature'));
        $this->assertSame(['parse' => []], $offline['allowed_mentions']);
        $this->assertSame('ServerScope', $offline['username']);

        $embed = $offline['embeds'][0];
        $this->assertSame('Server offline', $embed['title']);
        $this->assertSame(0xE5484D, $embed['color']);
        $this->assertSame('ServerScope', $embed['author']['name']);
        $this->assertStringContainsString('web-01 reageert niet', $embed['description']);
        $this->assertSame(route('incidents.show', $incident), $embed['url']);
        $fields = array_column($embed['fields'], 'value', 'name');
        $this->assertSame('Kritiek', $fields['Ernst']);
        $this->assertSame('web-01', $fields['Server']);
        $this->assertSame('Open', $fields['Status']);
        $this->assertNotEmpty($embed['timestamp']);

        $this->assertSame('Server weer online', $online['embeds'][0]['title']);
        $this->assertSame(0x30A46C, $online['embeds'][0]['color']);
        $this->assertSame('Opgelost', array_column($online['embeds'][0]['fields'], 'value', 'name')['Status']);
    }

    public function test_domain_and_alert_sources_get_specific_titles(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        $base = ['id' => 5, 'title' => 'x', 'severity' => 'warning', 'status' => 'open', 'subject' => ['type' => $domain->getMorphClass(), 'id' => $domain->id, 'name' => 'example.com'], 'opened_at' => now()->toIso8601String()];

        $titles = [
            ['incident.opened', 'http', 'Website onbereikbaar'],
            ['incident.resolved', 'http', 'Website weer bereikbaar'],
            ['incident.opened', 'ssl_expiry', 'SSL-certificaat verloopt binnenkort'],
            ['incident.opened', 'ssl', 'Probleem met SSL-certificaat'],
            ['incident.opened', 'dns', 'DNS-resolutie mislukt'],
            ['incident.opened', 'expiry', 'Domeinregistratie verloopt binnenkort'],
            ['incident.escalated', 'expiry', 'Escalatie: domeinregistratie verloopt binnenkort'],
            ['incident.opened', 'alert', 'Monitoring-alert geactiveerd'],
            ['incident.resolved', 'alert', 'Monitoring-alert opgelost'],
            ['incident.reopened', 'agent', 'Opnieuw: server offline'],
        ];

        foreach ($titles as [$event, $source, $title]) {
            $embed = DiscordMessage::fromPayload(['event' => $event, 'data' => ['incident' => [...$base, 'source' => $source]]])['embeds'][0];
            $this->assertSame($title, $embed['title'], "{$event} / {$source}");
            $this->assertSame('example.com', array_column($embed['fields'], 'value', 'name')['Domein']);
        }

        $this->assertSame(0xF5A524, DiscordMessage::fromPayload(['event' => 'incident.opened', 'data' => ['incident' => [...$base, 'source' => 'http']]])['embeds'][0]['color']);
        $this->assertSame(0x3B82F6, DiscordMessage::fromPayload(['event' => 'incident.opened', 'data' => ['incident' => [...$base, 'severity' => 'info', 'source' => 'http']]])['embeds'][0]['color']);

        // Monitored names cannot ping a channel.
        $embed = DiscordMessage::fromPayload(['event' => 'incident.opened', 'data' => ['incident' => [...$base, 'source' => 'http', 'title' => '@everyone down']]])['embeds'][0];
        $this->assertStringNotContainsString('@everyone', $embed['description']);
    }

    public function test_dns_changes_and_test_messages(): void
    {
        $endpoint = $this->discord();
        Http::fake(['https://discord.com/*' => Http::response('', 204)]);
        $domain = Domain::create(['name' => 'example.com']);
        $change = $domain->dnsChanges()->make(['record_type' => 'MX', 'added' => ['10 mx.example.net.'], 'removed' => ['10 mx.old.example.'], 'detected_at' => now()]);

        app(WebhookDispatcher::class)->dnsChange($domain, [$change]);
        $this->actingAs($this->admin)->post(route('webhooks.test', $endpoint))->assertSessionHas('success');

        $bodies = [];
        Http::assertSent(function (Request $request) use (&$bodies) {
            $bodies[] = json_decode($request->body(), true)['embeds'][0];

            return true;
        });

        $this->assertSame('DNS-records gewijzigd', $bodies[0]['title']);
        $this->assertStringContainsString('+ 10 mx.example.net.', $bodies[0]['fields'][0]['value']);
        $this->assertSame(route('domains.show', $domain), $bodies[0]['url']);
        $this->assertSame('Testmelding', $bodies[1]['title']);
    }

    public function test_failed_delivery_is_recorded_without_leaking_the_token(): void
    {
        $endpoint = $this->discord();

        // Webhook removed in Discord: permanent failure.
        Http::fake(['*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);
        $delivery = $endpoint->deliveries()->create(['uuid' => (string) Str::uuid(), 'event' => 'test', 'payload' => ['event' => 'test']]);
        (new DeliverWebhook($delivery->id))->handle(app(SafeHttpClient::class));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(404, $delivery->response_status);
        $this->assertSame(1, $endpoint->fresh()->consecutive_failures);

        // Transport errors that echo the request URL are redacted.
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.self::URL));
        $delivery = $endpoint->deliveries()->create(['uuid' => (string) Str::uuid(), 'event' => 'test', 'payload' => ['event' => 'test']]);
        (new DeliverWebhook($delivery->id))->handle(app(SafeHttpClient::class));

        $delivery->refresh();
        $this->assertSame('retrying', $delivery->status, 'network errors are retried');
        $this->assertNotNull($delivery->error);
        $this->assertStringNotContainsString(self::TOKEN, $delivery->error);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('webhook_deliveries')->get()));

        $this->actingAs($this->admin)->get(route('webhooks.show', $endpoint))->assertOk()->assertSee('Mislukt')->assertDontSee(self::TOKEN, false);
    }

    public function test_rate_limited_delivery_is_retried(): void
    {
        $endpoint = $this->discord();
        Http::fake(['*' => Http::response(['retry_after' => 1.5], 429)]);
        $delivery = $endpoint->deliveries()->create(['uuid' => (string) Str::uuid(), 'event' => 'test', 'payload' => ['event' => 'test']]);

        (new DeliverWebhook($delivery->id))->handle(app(SafeHttpClient::class));

        $this->assertSame('retrying', $delivery->fresh()->status);
    }

    public function test_repeated_events_are_not_posted_twice_and_severity_filter_applies(): void
    {
        config(['serverscope.incidents.reopen_window_minutes' => 60]);
        $discord = $this->discord();
        $generic = WebhookEndpoint::query()->create([
            'name' => 'Hook', 'url' => 'https://hooks.example.com/in', 'secret' => 'whsec_test',
            'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true,
        ]);
        Http::fake(['*' => Http::response('', 204)]);
        $manager = app(IncidentManager::class);

        // Flapping: open → resolve → reopen → resolve → reopen.
        $manager->trigger(new Signal('flap', 'http', 'warning', 'Site down'));
        $manager->recover('flap', 'ok');
        $manager->trigger(new Signal('flap', 'http', 'warning', 'Site down'));
        $manager->recover('flap', 'ok');
        $manager->trigger(new Signal('flap', 'http', 'warning', 'Site down'));

        $this->assertSame(['incident.opened', 'incident.resolved', 'incident.reopened'], $discord->deliveries()->orderBy('id')->pluck('event')->all());
        $this->assertSame(5, $generic->deliveries()->count(), 'machine endpoints still receive every event');

        // Repeated failures of an active incident never post again.
        $manager->trigger(new Signal('flap', 'http', 'warning', 'Site down'));
        $this->assertSame(3, $discord->deliveries()->count());

        // Minimum severity.
        $discord->forceFill(['min_severity' => 'critical'])->save();
        $manager->trigger(new Signal('minor', 'http', 'warning', 'Traag'));
        $this->assertSame(3, $discord->deliveries()->count());
        $manager->trigger(new Signal('major', 'http', 'critical', 'Plat'));
        $this->assertSame(4, $discord->deliveries()->count());

        // After the cooldown the same event may be posted again.
        $this->travel(16)->minutes();
        $manager->recover('flap', 'ok');
        $this->assertSame(4, $discord->deliveries()->count(), 'warning is below the minimum severity now');
        $discord->forceFill(['min_severity' => null])->save();
        $manager->trigger(new Signal('flap', 'http', 'warning', 'Site down'));
        $this->assertSame('incident.reopened', $discord->deliveries()->latest('id')->value('event'));
    }

    public function test_masking_and_redaction_helpers(): void
    {
        $this->assertTrue(DiscordMessage::isValidUrl(self::URL));
        $this->assertFalse(DiscordMessage::isValidUrl(self::URL.'?thread_id=1'));
        $this->assertStringNotContainsString(self::TOKEN, DiscordMessage::mask(self::URL));
        $this->assertSame('POST to https://discord.com/api/webhooks/123456789012345678/•••••• failed', DiscordMessage::redact('POST to '.self::URL.' failed'));
    }
}
