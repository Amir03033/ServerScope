<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Monitoring\Dns\Resolver;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Notifications\ChatChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/** Slack, Microsoft Teams and Telegram: connected like Discord, messages in each channel's own format. */
class ChatChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK = 'https://hooks.slack.com/services/T0001ABCD/B0002EFGH/SlackSecretToken1234567890';

    private const TEAMS = 'https://prod-42.westeurope.logic.azure.com:443/workflows/abc123/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=TeamsSecretSignature123';

    private const BOT = '123456789:AAHteleGramSecretTokenABCDEFGHIJKLMNOP';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->app->instance(Resolver::class, (new FakeResolver)
            ->host('hooks.slack.com', '34.226.36.50')
            ->host('prod-42.westeurope.logic.azure.com', '20.61.15.1')
            ->host('api.telegram.org', '149.154.167.220'));
        $this->admin = User::factory()->create(['is_admin' => true]);
        // Never talk to the real services from tests.
        Http::preventStrayRequests();
    }

    private function connect(string $type, array $fields): WebhookEndpoint
    {
        $this->actingAs($this->admin)->post(route('webhooks.store'), ['name' => ucfirst($type).' ops', 'type' => $type, 'events' => ['incident.opened', 'incident.resolved'], 'is_active' => '1', ...$fields])
            ->assertRedirect()->assertSessionHasNoErrors();

        return WebhookEndpoint::query()->where('type', $type)->sole();
    }

    /** @return array<int, array<string, mixed>> */
    private function incidentRoundTrip(): array
    {
        $server = Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test']);
        $manager = app(IncidentManager::class);
        $incident = $manager->trigger(new Signal('test:chat', 'agent', 'critical', 'web-01 <b>down</b> & out', 'Geen metingen ontvangen.', $server));
        $manager->recover('test:chat', 'Agent meldt zich weer.');

        $this->assertSame(['success', 'success'], WebhookDelivery::query()->orderBy('id')->pluck('status')->all());
        $sent = [];
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true), 'signed' => $request->hasHeader('X-ServerScope-Signature')];

            return true;
        });

        return [$incident, ...$sent];
    }

    public function test_slack_gets_a_coloured_message_with_view_and_acknowledge_links(): void
    {
        $endpoint = $this->connect('slack', ['url' => self::SLACK]);
        $this->assertSame(self::SLACK, $endpoint->secret);
        $this->assertStringNotContainsString('SlackSecretToken', $endpoint->url);
        $this->get(route('webhooks.show', $endpoint))->assertOk()->assertDontSee('SlackSecretToken');

        Http::fake(['https://hooks.slack.com/*' => Http::response('ok')]);
        [$incident, $opened, $resolved] = $this->incidentRoundTrip();

        $this->assertSame(self::SLACK, $opened['url']);
        $this->assertFalse($opened['signed']);
        $attachment = $opened['body']['attachments'][0];
        $this->assertSame('#E5484D', $attachment['color']);
        $this->assertSame('Server offline', $attachment['blocks'][0]['text']['text']);
        $text = json_encode($attachment['blocks']);
        $this->assertStringContainsString('web-01 &lt;b&gt;down&lt;\/b&gt; &amp; out', $text);
        $this->assertStringContainsString(str_replace('/', '\/', route('incidents.acknowledge', $incident)), $text);
        $this->assertSame('#30A46C', $resolved['body']['attachments'][0]['color']);
        $this->assertStringNotContainsString('acknowledge', json_encode($resolved['body']), 'no acknowledge once resolved');
    }

    public function test_teams_gets_an_adaptive_card_with_buttons(): void
    {
        $this->connect('teams', ['url' => self::TEAMS]);
        Http::fake(['https://prod-42.westeurope.logic.azure.com*' => Http::response('', 202)]);

        [$incident, $opened] = $this->incidentRoundTrip();

        $card = $opened['body']['attachments'][0];
        $this->assertSame('application/vnd.microsoft.card.adaptive', $card['contentType']);
        $this->assertSame('Attention', $card['content']['body'][0]['color']);
        $this->assertSame([route('incidents.show', $incident), route('incidents.acknowledge', $incident)], array_column($card['content']['actions'], 'url'));
        $facts = array_column($card['content']['body'][3]['facts'], 'value', 'title');
        $this->assertSame('Kritiek', $facts['Ernst']);
    }

    public function test_telegram_needs_a_bot_token_and_chat_and_gets_html_text(): void
    {
        $this->actingAs($this->admin)->post(route('webhooks.store'), ['name' => 'TG', 'type' => 'telegram', 'token' => 'not-a-token', 'target' => 'my group', 'events' => ['incident.opened']])
            ->assertSessionHasErrors('token')->assertSessionDoesntHaveErrors('url');

        $endpoint = $this->connect('telegram', ['token' => self::BOT, 'target' => '-1001234567890']);
        $this->assertSame([self::BOT, '-1001234567890', 'Telegram · -1001234567890'], [$endpoint->secret, $endpoint->target, $endpoint->url]);
        $this->get(route('webhooks.edit', $endpoint))->assertOk()->assertDontSee('AAHteleGram');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        [, $opened] = $this->incidentRoundTrip();

        $this->assertSame('https://api.telegram.org/bot'.self::BOT.'/sendMessage', $opened['url']);
        $this->assertSame(['-1001234567890', 'HTML'], [$opened['body']['chat_id'], $opened['body']['parse_mode']]);
        $this->assertStringContainsString('<b>web-01 &lt;b&gt;down&lt;/b&gt; &amp; out</b>', $opened['body']['text']);

        // Editing without a new token keeps the stored one.
        $this->put(route('webhooks.update', $endpoint), ['name' => 'TG', 'target' => '@opsalerts', 'events' => ['incident.opened'], 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([self::BOT, '@opsalerts'], [$endpoint->fresh()->secret, $endpoint->fresh()->target]);
    }

    public function test_only_real_channel_addresses_are_accepted_and_tokens_never_stored_in_errors(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('webhooks.store'), ['name' => 'x', 'type' => 'slack', 'url' => 'https://evil.example.com/services/T/B/x', 'events' => ['incident.opened']])->assertSessionHasErrors('url');
        $this->post(route('webhooks.store'), ['name' => 'x', 'type' => 'teams', 'url' => 'https://example.com/workflows/x', 'events' => ['incident.opened']])->assertSessionHasErrors('url');
        $this->assertSame(0, WebhookEndpoint::query()->count());

        $this->assertStringNotContainsString('SlackSecretToken', ChatChannel::redact('cURL error 28 for '.self::SLACK));
        $this->assertStringNotContainsString('TeamsSecretSignature', ChatChannel::redact('error for '.self::TEAMS));
        $this->assertStringNotContainsString('AAHteleGram', ChatChannel::redact('https://api.telegram.org/bot'.self::BOT.'/sendMessage'));

        // A failed delivery keeps the error, without the token.
        $endpoint = $this->connect('slack', ['url' => self::SLACK]);
        Http::fake(['https://hooks.slack.com/*' => Http::response('invalid_token '.self::SLACK, 403)]);
        $this->post(route('webhooks.test', $endpoint))->assertRedirect();
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringNotContainsString('SlackSecretToken', (string) $delivery->response_body);
    }

    public function test_acknowledge_from_chat_confirms_first_and_needs_a_login(): void
    {
        $server = Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test']);
        $incident = app(IncidentManager::class)->trigger(new Signal('test:ack', 'agent', 'critical', 'web-01 offline', null, $server));

        auth()->logout();
        $this->get(route('incidents.acknowledge', $incident))->assertRedirect(route('login'));

        $this->actingAs($this->admin)->get(route('incidents.acknowledge', $incident))->assertOk()->assertSee('web-01 offline');
        $this->assertSame('open', $incident->fresh()->status, 'opening the link changes nothing');

        $this->from(route('incidents.acknowledge', $incident))->post(route('incidents.transition', $incident), ['status' => 'acknowledged'])->assertRedirect(route('incidents.acknowledge', $incident));
        $this->assertSame('acknowledged', $incident->fresh()->status);
        $this->get(route('incidents.acknowledge', $incident))->assertRedirect(route('incidents.show', $incident));
    }
}
