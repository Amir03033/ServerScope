<?php

namespace Tests\Feature\Localization;

use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\IncidentNotification;
use App\Notifications\TestNotification;
use App\Services\Notifications\DiscordMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebhookDispatcher;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Messages go out in the language of the recipient (PR 8): e-mail and
 * in-app per user, Discord and webhooks per endpoint creator. The JSON
 * structure of webhooks stays the same.
 */
class RecipientLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function incident(): Incident
    {
        return Incident::query()->create([
            'fingerprint' => 'manual:locale', 'source' => 'http', 'severity' => 'critical', 'status' => 'open',
            'title' => 'shop.example.com is onbereikbaar', 'opened_at' => now(), 'last_occurrence_at' => now(),
        ]);
    }

    /** @return array<int, \Symfony\Component\Mime\Email> */
    private function sentMails(): array
    {
        return array_map(fn ($message) => $message->getOriginalMessage(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }

    public function test_users_get_e_mail_in_their_own_language(): void
    {
        $en = User::factory()->create(['locale' => 'en', 'email' => 'en@example.test']);
        $nl = User::factory()->create(['locale' => 'nl', 'email' => 'nl@example.test']);
        $incident = $this->incident();

        $en->notify(new IncidentNotification($incident, IncidentNotification::OPENED, ['mail']));
        $nl->notify(new IncidentNotification($incident, IncidentNotification::OPENED, ['mail']));

        $mails = collect($this->sentMails())->keyBy(fn ($mail) => $mail->getTo()[0]->getAddress());

        $this->assertSame('[ServerScope] Critical incident: shop.example.com is onbereikbaar', $mails['en@example.test']->getSubject());
        $this->assertStringContainsString('There is a new incident', $mails['en@example.test']->getHtmlBody());
        $this->assertStringContainsString('Severity: Critical', $mails['en@example.test']->getHtmlBody());
        $this->assertStringContainsString('View incident', $mails['en@example.test']->getHtmlBody());

        $this->assertSame('[ServerScope] Kritiek incident: shop.example.com is onbereikbaar', $mails['nl@example.test']->getSubject());
        $this->assertStringContainsString('Er is een nieuw incident', $mails['nl@example.test']->getHtmlBody());
        $this->assertStringContainsString('Met vriendelijke groet', $mails['nl@example.test']->getHtmlBody());
        $this->assertStringContainsString('Incident bekijken', $mails['nl@example.test']->getHtmlBody());

        // Sending in another language does not change the language of the request.
        $this->assertSame('nl', app()->getLocale());
    }

    public function test_in_app_notifications_are_stored_in_the_language_of_the_reader(): void
    {
        $en = User::factory()->create(['locale' => 'en']);
        $nl = User::factory()->create(['locale' => 'nl']);

        $en->notify(new TestNotification(['database']));
        $nl->notify(new TestNotification(['database']));

        $this->assertSame('Test notification: in-app notifications work.', $en->notifications()->first()->data['message']);
        $this->assertSame('Testmelding: in-app meldingen werken.', $nl->notifications()->first()->data['message']);
    }

    public function test_the_delivery_log_shows_the_subject_as_the_recipient_got_it(): void
    {
        Queue::fake();
        $en = User::factory()->create(['locale' => 'en', 'notify_email' => true]);

        app(NotificationDispatcher::class)->test($en);

        $this->assertSame('[ServerScope] Test notification', NotificationLog::query()->where('user_id', $en->id)->value('subject'));
    }

    public function test_discord_follows_the_language_of_the_endpoint_creator(): void
    {
        $en = User::factory()->create(['locale' => 'en']);
        $nl = User::factory()->create(['locale' => 'nl']);
        $payload = ['event' => 'incident.opened', 'data' => ['incident' => ['id' => 1, 'source' => 'agent', 'severity' => 'critical', 'status' => 'open', 'title' => 'web-01']]];

        $byEnglish = WebhookEndpoint::query()->create(['name' => 'a', 'type' => 'discord', 'url' => 'x', 'secret' => 'x', 'events' => [], 'created_by' => $en->id]);
        $byDutch = WebhookEndpoint::query()->create(['name' => 'b', 'type' => 'discord', 'url' => 'x', 'secret' => 'x', 'events' => [], 'created_by' => $nl->id]);
        $nobody = WebhookEndpoint::query()->create(['name' => 'c', 'type' => 'discord', 'url' => 'x', 'secret' => 'x', 'events' => []]);

        $this->assertSame('en', Locale::forEndpoint($byEnglish));
        $this->assertSame('nl', Locale::forEndpoint($byDutch));
        $this->assertSame(config('app.locale'), Locale::forEndpoint($nobody));

        $english = Locale::using('en', fn () => DiscordMessage::fromPayload($payload))['embeds'][0];
        $dutch = Locale::using('nl', fn () => DiscordMessage::fromPayload($payload))['embeds'][0];

        $this->assertSame('Server offline', $english['title']);
        $this->assertSame(['Status', 'Severity', 'Source'], array_slice(array_column($english['fields'], 'name'), 0, 3));
        $this->assertSame('Critical', array_column($english['fields'], 'value', 'name')['Severity']);
        $this->assertSame('ServerScope · Incident opened', $english['footer']['text']);
        $this->assertSame(['Status', 'Ernst', 'Bron'], array_slice(array_column($dutch['fields'], 'name'), 0, 3));
        $this->assertSame('ServerScope · Incident geopend', $dutch['footer']['text']);

        // Same structure in both languages.
        $this->assertSame(array_keys($english), array_keys($dutch));
    }

    public function test_webhook_json_keeps_its_structure_and_only_readable_text_follows_the_language(): void
    {
        Queue::fake();
        $en = User::factory()->create(['locale' => 'en']);
        $endpoint = WebhookEndpoint::query()->create(['name' => 'n8n', 'type' => 'generic', 'url' => 'https://hooks.example.com/x', 'secret' => 's', 'events' => ['incident.opened'], 'is_active' => true, 'created_by' => $en->id]);

        $delivery = app(WebhookDispatcher::class)->test($endpoint);

        $this->assertSame(['id', 'event', 'created_at', 'data'], array_keys($delivery->payload));
        $this->assertSame(['message'], array_keys($delivery->payload['data']));
        $this->assertSame('Test delivery from ServerScope.', $delivery->payload['data']['message']);

        // The incident payload (the API resource) is identical whatever the language.
        $incident = $this->incident();
        $nl = Locale::using('nl', fn () => json_encode((new IncidentResource($incident))->resolve()));
        $english = Locale::using('en', fn () => json_encode((new IncidentResource($incident))->resolve()));
        $this->assertSame($nl, $english);
    }

    public function test_preferred_locale_is_always_supported(): void
    {
        $this->assertSame('nl', User::factory()->make(['locale' => 'nl'])->preferredLocale());
        $this->assertSame('en', User::factory()->make(['locale' => 'de'])->preferredLocale());
    }
}
