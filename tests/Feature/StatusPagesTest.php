<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StatusPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function setupPage(array $overrides = []): array
    {
        $server = Server::create(['name' => 'db-prod-01', 'hostname' => 'db01.internal.example.net', 'ip_address' => '10.20.30.40', 'last_seen_at' => now()->subHour()]);
        $domain = Domain::create(['name' => 'shop.example.com', 'path' => '/secret-health']);
        $domain->forceFill(['status' => 'healthy'])->save();
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => now()->subHour()]);

        $this->actingAs($this->admin)->post(route('status-pages.store'), [
            'name' => 'Status Example', 'slug' => '', 'description' => 'Publieke status', 'accent_color' => '#38bdf8',
            'is_public' => '1', 'show_uptime' => '1', 'show_incident_titles' => '0',
            'components' => [
                ['subject' => 'domain:'.$domain->id, 'display_name' => 'Webwinkel', 'description' => 'Bestellen'],
                ['subject' => 'server:'.$server->id, 'display_name' => 'Database', 'description' => ''],
            ],
            ...$overrides,
        ])->assertRedirect(route('status-pages.index'));

        return [StatusPage::query()->latest('id')->first(), $server, $domain];
    }

    public function test_management_is_admin_only_and_validated(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get(route('status-pages.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('status-pages.store'), ['name' => 'x'])->assertForbidden();

        [$page] = $this->setupPage();
        $this->assertSame('status-example', $page->slug);
        $this->assertSame(['Webwinkel', 'Database'], $page->components->pluck('display_name')->all());

        $this->actingAs($this->admin)->post(route('status-pages.store'), ['name' => 'Ander', 'slug' => 'status-example', 'accent_color' => '#000000'])->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->post(route('status-pages.store'), ['name' => 'Ander', 'accent_color' => 'red'])->assertSessionHasErrors('accent_color');
        $this->actingAs($this->admin)->post(route('status-pages.store'), ['name' => 'Ander', 'accent_color' => '#000000', 'components' => [['subject' => 'user:1', 'display_name' => 'x']]])->assertSessionHasErrors('components.0.subject');
        $this->actingAs($this->admin)->post(route('status-pages.store'), ['name' => 'Ander', 'accent_color' => '#000000', 'components' => [['subject' => 'server:999', 'display_name' => 'x']]])->assertSessionHasErrors('components.0.subject');
    }

    public function test_public_page_shows_only_approved_information(): void
    {
        [$page, $server, $domain] = $this->setupPage();
        app(IncidentManager::class)->trigger(new Signal('agent:x', 'agent', 'critical', 'db-prod-01 reageert niet (agent offline)', 'Interne notitie: 10.20.30.40 onbereikbaar via vpn', $server));
        auth()->logout();

        $html = $this->get(route('status.show', $page->slug))->assertOk()
            ->assertSee(['Status Example', 'Webwinkel', 'Database', 'Operationeel', 'Grote storing', 'Er is een storing', 'Storing bij Database'])
            ->getContent();

        foreach (['db-prod-01', 'db01.internal.example.net', '10.20.30.40', 'shop.example.com', 'secret-health', 'Interne notitie', 'vpn'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "{$secret} must not be published");
        }

        $json = $this->getJson(route('status.json', $page->slug))->assertOk()
            ->assertJsonPath('overall', 'major_outage')
            ->assertJsonPath('components.0.name', 'Webwinkel')
            ->assertJsonPath('components.0.state', 'operational')
            ->getContent();
        $this->assertStringNotContainsString('10.20.30.40', $json);
        $this->assertStringNotContainsString('shop.example.com', $json);
    }

    public function test_incident_titles_only_when_enabled(): void
    {
        [$page, $server] = $this->setupPage(['show_incident_titles' => '1']);
        app(IncidentManager::class)->trigger(new Signal('agent:y', 'agent', 'critical', 'Database onderhoud loopt uit', null, $server));

        $this->get(route('status.show', $page->slug))->assertSee('Database onderhoud loopt uit');
    }

    public function test_uptime_bars_use_real_data_and_missing_days_have_no_value(): void
    {
        // Midday in the display timezone, so "an hour ago" is today.
        $this->travelTo(now('Europe/Amsterdam')->setTime(12, 0)->utc());

        [$page] = $this->setupPage();

        $data = $this->getJson(route('status.json', $page->slug))->json();
        $domainDays = collect($data['components'][0]['uptime']);

        $this->assertCount(90, $domainDays);
        $this->assertSame(100, $domainDays->last()['uptime']);
        $this->assertNull($domainDays->first()['uptime']);
    }

    public function test_private_pages_require_login_and_unknown_slugs_404(): void
    {
        [$page] = $this->setupPage(['is_public' => '0']);
        auth()->logout();

        $this->get(route('status.show', $page->slug))->assertRedirect(route('login'));
        $this->get(route('status.json', $page->slug))->assertRedirect(route('login'));
        $this->get('/status/bestaat-niet')->assertNotFound();

        $this->actingAs(User::factory()->create())->get(route('status.show', $page->slug))->assertOk();
    }

    public function test_deleted_monitors_disappear_from_the_page(): void
    {
        [$page, $server] = $this->setupPage();
        $server->delete();

        $data = $this->getJson(route('status.json', $page->slug))->json();

        $this->assertSame(['Webwinkel'], array_column($data['components'], 'name'));
        $this->assertSame('operational', $data['overall']);
    }

    public function test_maintenance_is_shown_as_maintenance(): void
    {
        [$page, $server] = $this->setupPage();
        $server->forceFill(['maintenance_starts_at' => now()->subMinute()])->save();
        Cache::flush();

        $this->getJson(route('status.json', $page->slug))->assertJsonPath('components.1.state', 'maintenance');
    }
}
