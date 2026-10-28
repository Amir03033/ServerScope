<?php

namespace Tests\Feature\Tenancy;

use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\ResourceDependency;
use App\Monitoring\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * An API token of team A can neither read nor change anything of team B.
 * Response shapes of existing endpoints are unchanged.
 */
class ApiIsolationTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, (new FakeResolver)->host('alpha-shop.example.com', '93.184.215.14')->host('bravo-shop.example.com', '93.184.215.15'));
        $this->createTwoTeams();
    }

    public function test_lists_only_contain_own_team(): void
    {
        $headers = $this->apiHeaders($this->a['plain']);
        $this->b['incident']->forceFill(['status' => 'open'])->save();

        foreach (['/api/v1/servers', '/api/v1/domains', '/api/v1/incidents?state=all', '/api/v1/dependencies', '/api/v1/predictions', "/api/v1/domains/{$this->a['domain']->id}/pages"] as $url) {
            $response = $this->getJson($url, $headers)->assertOk()->assertJsonStructure(['data']);
            $this->assertStringContainsString('alpha', $response->getContent(), $url);
            $this->assertStringNotContainsStringIgnoringCase('bravo', $response->getContent(), "Team B leaked on {$url}");
        }

        $this->getJson('/api/v1/servers', $headers)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/predictions', $headers)->assertJsonCount(1, 'data');
    }

    public function test_reading_resources_of_another_team_returns_404(): void
    {
        $headers = $this->apiHeaders($this->a['plain']);
        $b = $this->b;

        foreach ([
            "/api/v1/servers/{$b['server']->id}",
            "/api/v1/servers/{$b['server']->id}/metrics",
            "/api/v1/servers/{$b['server']->id}/services",
            "/api/v1/domains/{$b['domain']->id}",
            "/api/v1/domains/{$b['domain']->id}/checks",
            "/api/v1/domains/{$b['domain']->id}/pages",
            "/api/v1/domains/{$b['domain']->id}/discovery-runs",
            "/api/v1/pages/{$b['page']->id}",
            "/api/v1/pages/{$b['page']->id}/checks",
            "/api/v1/incidents/{$b['incident']->id}",
        ] as $url) {
            $this->getJson($url, $headers)->assertNotFound();
        }
    }

    public function test_writing_resources_of_another_team_returns_404_and_changes_nothing(): void
    {
        $headers = $this->apiHeaders($this->a['plain']);
        $b = $this->b;

        $this->postJson("/api/v1/domains/{$b['domain']->id}/pages", ['urls' => ['/nieuw']], $headers)->assertNotFound();
        $this->putJson("/api/v1/pages/{$b['page']->id}", ['urls' => ['/x']], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/pages/{$b['page']->id}", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/pages/{$b['page']->id}/pause", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/domains/{$b['domain']->id}/discovery", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/dependencies/{$b['dependency']->id}", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/incidents/{$b['incident']->id}/assign", ['user_id' => $this->a['admin']->id], $headers)->assertNotFound();
        $this->postJson("/api/v1/incidents/{$b['incident']->id}/acknowledge", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/incidents/{$b['incident']->id}/resolve", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/incidents/{$b['incident']->id}/comments", ['message' => 'x'], $headers)->assertNotFound();

        $incident = Incident::withoutGlobalScopes()->find($b['incident']->id);
        $this->assertSame('open', $incident->status);
        $this->assertNull($incident->assigned_to);
        $this->assertSame(0, $incident->events()->where('type', 'comment')->count());
        $this->assertTrue(MonitoredPage::withoutGlobalScopes()->whereKey($b['page']->id)->where('is_active', true)->exists());
        $this->assertTrue(ResourceDependency::withoutGlobalScopes()->whereKey($b['dependency']->id)->exists());
    }

    public function test_dependencies_and_assignments_cannot_cross_teams(): void
    {
        $headers = $this->apiHeaders($this->a['plain']);

        $this->postJson('/api/v1/dependencies', ['dependent_type' => 'domain', 'dependent_id' => $this->a['domain']->id, 'dependency_type' => 'server', 'dependency_id' => $this->b['server']->id], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'Onbekende resource.');
        $this->postJson('/api/v1/dependencies', ['dependent_type' => 'page', 'dependent_id' => $this->b['page']->id, 'dependency_type' => 'server', 'dependency_id' => $this->a['server']->id], $headers)
            ->assertStatus(422);

        $this->postJson("/api/v1/incidents/{$this->a['incident']->id}/assign", ['user_id' => $this->b['admin']->id], $headers)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertNull($this->a['incident']->fresh()->assigned_to);

        $this->postJson("/api/v1/incidents/{$this->a['incident']->id}/assign", ['user_id' => $this->a['member']->id], $headers)->assertOk()
            ->assertJsonPath('data.assigned_to', $this->a['member']->id);
    }

    public function test_a_token_stops_working_when_its_owner_leaves_the_team(): void
    {
        $headers = $this->apiHeaders($this->a['plain']);
        $this->getJson('/api/v1/servers', $headers)->assertOk();

        $this->a['admin']->teams()->detach($this->a['team']->id);

        $this->getJson('/api/v1/servers', $headers)->assertUnauthorized()->assertJsonPath('message', 'Ontbrekend of ongeldig API-token.');
    }

    public function test_a_token_is_bound_to_its_own_team_even_when_the_owner_is_in_several(): void
    {
        // The owner of team A also joins team B and switches to it: the
        // existing token of team A still only sees team A.
        $this->a['admin']->teams()->attach($this->b['team']->id, ['role' => 'member']);
        $this->a['admin']->forceFill(['current_team_id' => $this->b['team']->id])->save();

        $response = $this->getJson('/api/v1/servers', $this->apiHeaders($this->a['plain']))->assertOk();
        $this->assertStringContainsString('alpha-server', $response->getContent());
        $this->assertStringNotContainsString('bravo', $response->getContent());
    }

    public function test_agents_keep_working_and_only_feed_their_own_team(): void
    {
        $this->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 99.9], ['Authorization' => 'Bearer '.hash('sha256', 'x')])->assertUnauthorized();

        $token = bin2hex(random_bytes(32));
        $this->b['server']->agentCredentials()->create(['name' => 'nieuw', 'token_hash' => hash('sha256', $token)]);

        $this->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 99.9], ['Authorization' => 'Bearer '.$token])->assertCreated();

        // Team B's own rule (>= 99) fired; team A's identical rule did not.
        $alerts = \App\Models\Alert::withoutGlobalScopes()->where('server_id', $this->b['server']->id)->where('status', 'active')->get();
        $this->assertSame([$this->b['rule']->id], $alerts->pluck('alert_rule_id')->all());
        $this->assertSame($this->b['team']->id, $alerts->first()->team_id);
    }
}
