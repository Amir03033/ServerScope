<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administration, profile and search after the rebuild: both languages, no
 * inline code, and no screen loads the old transition stylesheet.
 */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(string $locale = 'nl'): User
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => $locale]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function webhook(): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create(['name' => 'n8n', 'type' => 'generic', 'url' => 'https://hooks.example.com/x', 'secret' => 'geheim', 'events' => ['incident.opened'], 'is_active' => true]);
    }

    /** @return list<string> */
    private function urls(WebhookEndpoint $webhook): array
    {
        return [
            route('users.index'), route('audit-logs.index'), route('system-health'), route('platform.agent-releases'),
            route('notifications.index'), route('notifications.index', ['tab' => 'deliveries']),
            route('webhooks.index'), route('webhooks.create'), route('webhooks.create', ['type' => 'discord']), route('webhooks.show', $webhook), route('webhooks.edit', $webhook),
            route('profile.edit'), route('api-tokens.index'), route('search', ['q' => 'web']),
        ];
    }

    public function test_the_screens_are_english_by_default(): void
    {
        $user = $this->platformAdmin('en');
        $webhook = $this->webhook();
        Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test']);
        app()->setLocale('en');

        $this->actingAs($user)->get(route('users.index'))->assertOk()->assertSee(['Invite user', 'Administrator'])->assertDontSee('Gebruiker uitnodigen');
        $this->actingAs($user)->get(route('audit-logs.index'))->assertOk()->assertSee('Who changed what')->assertDontSee('Wie heeft wat gewijzigd');
        $this->actingAs($user)->get(route('system-health'))->assertOk()->assertSee(['Checks on time', 'Notifications and webhooks'])->assertDontSee(['Controles op tijd', 'Wachtrij']);
        $this->actingAs($user)->get(route('platform.agent-releases'))->assertOk()->assertSee('Servers per version')->assertDontSee('Servers per versie');
        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee(['Mark all as read', 'Delivery history'])->assertDontSee('Verzendgeschiedenis');
        $this->actingAs($user)->get(route('webhooks.index'))->assertOk()->assertSee(['Connect Discord', 'Add webhook'])->assertDontSee('Discord koppelen');
        $this->actingAs($user)->get(route('webhooks.show', $webhook))->assertOk()->assertSee(['Test delivery', 'Rotate secret', 'Generic (signed JSON)'])->assertDontSee('Geheim vernieuwen');
        $this->actingAs($user)->get(route('webhooks.edit', $webhook))->assertOk()->assertSee(['Minimum severity (incidents)', 'High and higher', 'Incident escalated (threshold/severity/not acknowledged)'])->assertDontSee(['Hoog en hoger', 'Incident geëscaleerd']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee(['Change password', 'Two-factor authentication', 'Save preferences'])->assertDontSee(['Wachtwoord wijzigen', 'Tweestapsverificatie']);
        $this->actingAs($user)->get(route('api-tokens.index'))->assertOk()->assertSee(['New token', 'Write (acknowledge/resolve incidents, notes)'])->assertDontSee('Nieuw token');
        $this->actingAs($user)->get(route('search', ['q' => 'web']))->assertOk()->assertSee(['Search term', 'Servers (1)'])->assertDontSee('Zoekterm');
    }

    public function test_the_dutch_texts_are_unchanged(): void
    {
        $user = $this->platformAdmin();
        $webhook = $this->webhook();

        $this->actingAs($user)->get(route('system-health'))->assertOk()->assertSee(['Scheduler', 'Controles op tijd', 'Nog nooit een hartslag ontvangen.']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee(['Wachtwoord wijzigen', 'Meldingen onder “Meldingen” in de zijbalk.', 'Alles (laag en hoger)']);
        $this->actingAs($user)->get(route('webhooks.edit', $webhook))->assertOk()->assertSee(['Hoog en hoger', 'Incident geëscaleerd (drempel/ernst/niet bevestigd)']);
        $this->actingAs($user)->get(route('api-tokens.index'))->assertOk()->assertSee(['Schrijven (incidenten bevestigen/oplossen, notities)', 'Onbeperkt']);
    }

    public function test_the_screens_have_no_inline_code(): void
    {
        $user = $this->platformAdmin();
        $webhook = $this->webhook();

        foreach ($this->urls($webhook) as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $main = substr($html, strpos($html, '<main'));

            $this->assertDoesNotMatchRegularExpression('/<style|\sstyle="|\son[a-z]+="/', $html, "Inline style or handler on {$url}");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*type="application\/json")[^>]*>/', $main, "Inline script on {$url}");
        }
    }

    public function test_no_screen_loads_the_old_stylesheet(): void
    {
        $this->assertFileDoesNotExist(public_path('css/serverscope.css'));
        $this->get(route('login'))->assertOk()->assertDontSee('serverscope.css', false);

        $user = $this->platformAdmin();
        $webhook = $this->webhook();

        foreach ([...$this->urls($webhook), route('dashboard'), route('servers.index'), route('domains.index'), route('incidents.index'), route('reports.print')] as $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertDontSee('serverscope.css', false);
        }
    }
}
