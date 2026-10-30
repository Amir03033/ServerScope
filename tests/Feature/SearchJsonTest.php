<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Quick search (Ctrl/⌘ K): /search answers JSON for "Accept: application/json",
 * with exactly the queries and team scoping of the HTML page.
 */
class SearchJsonTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    public function test_json_contains_only_the_fields_the_dialog_shows(): void
    {
        $user = User::factory()->create();
        $server = Server::create(['name' => 'Webserver Alpha', 'hostname' => 'alpha.example.test', 'ip_address' => '203.0.113.7']);
        $domain = Domain::create(['name' => 'alpha.example.com']);
        $incident = app(IncidentManager::class)->trigger(new Signal('t:1', 'manual', 'warning', 'Alpha traag', 'Interne notitie', $server));

        $response = $this->actingAs($user)->getJson(route('search', ['q' => 'alpha']))->assertOk()
            ->assertJsonPath('q', 'alpha')
            ->assertJsonCount(3, 'results')
            ->assertJsonPath('results.0.type', 'server')
            ->assertJsonPath('results.0.title', 'Webserver Alpha')
            ->assertJsonPath('results.0.subtitle', 'alpha.example.test')
            ->assertJsonPath('results.0.url', route('servers.show', $server))
            ->assertJsonPath('results.1.type', 'domain')
            ->assertJsonPath('results.1.url', route('domains.show', $domain))
            ->assertJsonPath('results.2.type', 'incident')
            ->assertJsonPath('results.2.title', '#'.$incident->id.' Alpha traag')
            ->assertJsonPath('results.2.url', route('incidents.show', $incident));

        foreach ($response->json('results') as $result) {
            $this->assertSame(['type', 'title', 'subtitle', 'status', 'status_label', 'url'], array_keys($result));
        }

        $this->assertStringNotContainsString('Interne notitie', $response->getContent());
        $this->assertStringNotContainsString('203.0.113.7', $response->getContent());
    }

    public function test_json_and_html_return_the_same_results(): void
    {
        $user = User::factory()->create();
        Server::create(['name' => 'shop-web', 'hostname' => 'web.shop.test']);
        Server::create(['name' => 'shop-db', 'hostname' => 'db.shop.test']);
        Domain::create(['name' => 'shop.example.com']);
        app(IncidentManager::class)->trigger(new Signal('t:2', 'manual', 'critical', 'Shop onbereikbaar', null));

        foreach (['shop', 'web.shop', '%%', 'x', ''] as $q) {
            $html = $this->actingAs($user)->get(route('search', ['q' => $q]))->assertOk()->getContent();
            $json = $this->actingAs($user)->getJson(route('search', ['q' => $q]))->assertOk()->json('results');

            foreach ($json as $result) {
                $this->assertStringContainsString(e($result['url']), $html, "HTML misses {$result['url']} for '{$q}'");
            }

            $this->assertSame(
                substr_count($html, 'class="ov-row"'),
                count($json),
                "Different number of results for '{$q}'",
            );
        }
    }

    public function test_json_never_contains_results_of_another_team(): void
    {
        $this->createTwoTeams();
        $this->b['incident']->forceFill(['status' => 'open'])->save();

        foreach (['shop', 'storing', 'server', 'example', (string) $this->b['incident']->id, '#'.$this->b['incident']->id, 'bravo', '93.184.216'] as $q) {
            $response = $this->actingAs($this->a['admin'])->getJson(route('search', ['q' => $q]))->assertOk();

            $results = json_encode($response->json('results'));

            $this->assertStringNotContainsStringIgnoringCase('bravo', $results, "Team B leaked in the JSON search for '{$q}'");
            $this->assertStringNotContainsString(route('servers.show', $this->b['server']), $results);
            $this->assertStringNotContainsString(route('domains.show', $this->b['domain']), $results);
            $this->assertStringNotContainsString(route('incidents.show', $this->b['incident']), $results);
        }

        // And the other way around.
        $response = $this->actingAs($this->b['admin'])->getJson(route('search', ['q' => 'alpha']))->assertOk();
        $this->assertSame([], $response->json('results'));
    }

    public function test_json_requires_login_and_is_throttled_like_the_page(): void
    {
        $this->getJson(route('search', ['q' => 'alpha']))->assertUnauthorized();

        $route = app('router')->getRoutes()->getByName('search');
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    public function test_the_html_page_is_unchanged(): void
    {
        $user = User::factory()->create();
        Server::create(['name' => 'Webserver Alpha', 'hostname' => 'alpha.example.test']);

        $this->actingAs($user)->get(route('search', ['q' => 'alpha']))->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertViewIs('search')
            ->assertSee('Webserver Alpha')
            ->assertSee('Servers (1)');

        $this->actingAs($user)->get(route('search', ['q' => 'x']))->assertSee('Typ minimaal twee tekens.');
        $this->actingAs($user)->getJson(route('search', ['q' => 'x']))->assertExactJson(['q' => 'x', 'results' => []]);
    }

    public function test_validation_is_the_same_for_json(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('search', ['q' => str_repeat('a', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
