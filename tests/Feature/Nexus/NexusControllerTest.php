<?php

namespace Tests\Feature\Nexus;

use App\Http\Controllers\NexusController;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Services\Nexus\TopologyBuilder;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class NexusControllerTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, new FakeResolver);
        $this->createTwoTeams();
    }

    public function test_guests_are_redirected_or_get_401(): void
    {
        $this->get(route('nexus.index'))->assertRedirect(route('login'));
        $this->get(route('nexus.graph'))->assertRedirect(route('login'));
        $this->getJson(route('nexus.graph'))->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_a_user_who_is_no_member_of_their_team_is_signed_out(): void
    {
        $outsider = User::factory()->create();
        $outsider->forceFill(['current_team_id' => $this->b['team']->id])->save();

        $this->actingAs($outsider)->getJson(route('nexus.graph'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_members_get_the_topology_of_their_team(): void
    {
        $response = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'nodes' => [['key', 'type', 'id', 'label', 'status', 'health', 'url', 'parent', 'details', 'incidents' => ['active', 'max_severity', 'primary'], 'blast_radius']],
                'edges' => [['key', 'source', 'target', 'origin', 'label', 'dependency_id']],
                'meta' => ['version', 'generated_at', 'cache_ttl_seconds', 'max_depth', 'counts' => ['nodes', 'edges', 'by_type', 'active_incidents', 'unplaced_incidents'], 'truncated'],
            ]]);

        $keys = collect($response->json('data.nodes'))->pluck('key')->all();
        $this->assertContains('server:'.$this->a['server']->id, $keys);
        $this->assertContains('domain:'.$this->a['domain']->id, $keys);
        $this->assertContains("domain:{$this->a['domain']->id}->server:{$this->a['server']->id}", collect($response->json('data.edges'))->pluck('key')->all());
        $this->assertStringNotContainsStringIgnoringCase('bravo', $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk()->assertSee('NEXUS')->assertDontSee('bravo');
    }

    public function test_an_unchanged_topology_answers_304(): void
    {
        $first = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'), ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'), ['If-None-Match' => '"other"'])->assertOk();

        // The same ETag is never valid for another team.
        $this->actingAs($this->b['member'])->getJson(route('nexus.graph'), ['If-None-Match' => $etag])->assertOk();
    }

    public function test_the_etag_survives_a_rebuilt_cache_entry_but_not_a_change(): void
    {
        $etag = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->headers->get('ETag');

        // The entry expires and is rebuilt (a new generated_at), nothing else changed: still 304.
        $this->travel(TopologyBuilder::CACHE_TTL_SECONDS + 1)->seconds();
        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'), ['If-None-Match' => $etag])->assertStatus(304);

        // A real change gives a new version.
        Incident::query()->withoutGlobalScopes()->findOrFail($this->a['incident']->id)->forceFill(['severity' => 'info'])->save();
        $changed = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'), ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_the_topology_is_cached_per_team_and_cleared_by_incident_and_dependency_changes(): void
    {
        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertOk();
        $this->assertTrue(Cache::has(TopologyBuilder::cacheKey($this->a['team']->id)));
        $this->assertFalse(Cache::has(TopologyBuilder::cacheKey($this->b['team']->id)));

        // Monitoring updates (a heartbeat going quiet) show once the entry expires.
        $key = 'server:'.$this->a['server']->id;
        $health = fn () => collect($this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->json('data.nodes'))->firstWhere('key', $key)['status'];
        $this->a['server']->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->assertSame('online', $health());
        $this->travel(TopologyBuilder::CACHE_TTL_SECONDS + 1)->seconds();
        $this->assertSame('offline', $health());

        // Edits by people show at once.
        $this->a['server']->forceFill(['name' => 'alpha-hernoemd'])->save();
        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertSee('alpha-hernoemd');
        $this->actingAs($this->a['admin'])->post(route('servers.maintenance.start', $this->a['server']), ['duration_minutes' => 30, 'reason' => 'Updates']);
        $this->assertSame('maintenance', $health());

        // Incident state changes are visible at once.
        $incident = Incident::query()->withoutGlobalScopes()->findOrFail($this->a['incident']->id);
        $before = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->json('data.meta.counts.active_incidents');
        $incident->forceFill(['status' => 'resolved', 'active_fingerprint' => null, 'resolved_at' => now()])->save();
        $this->assertFalse(Cache::has(TopologyBuilder::cacheKey($this->a['team']->id)));
        $this->assertSame($before - 1, $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->json('data.meta.counts.active_incidents'));

        // So are dependency changes, and a change of team B leaves team A's entry alone.
        ResourceDependency::query()->withoutGlobalScopes()->findOrFail($this->b['dependency']->id)->delete();
        $this->assertTrue(Cache::has(TopologyBuilder::cacheKey($this->a['team']->id)));
        ResourceDependency::query()->withoutGlobalScopes()->findOrFail($this->a['dependency']->id)->delete();
        $this->assertFalse(Cache::has(TopologyBuilder::cacheKey($this->a['team']->id)));
        $edges = collect($this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->json('data.edges'))->pluck('key');
        $this->assertNotContains("domain:{$this->a['domain']->id}->server:{$this->a['server']->id}", $edges);
    }

    public function test_invalidation_only_touches_the_team_of_the_changed_record(): void
    {
        $aKey = TopologyBuilder::cacheKey($this->a['team']->id);
        $bKey = TopologyBuilder::cacheKey($this->b['team']->id);
        $warm = function () {
            $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertOk();
            $this->actingAs($this->b['member'])->getJson(route('nexus.graph'))->assertOk();
        };

        $changes = [
            'incident' => fn () => Incident::query()->withoutGlobalScopes()->findOrFail($this->b['incident']->id)->forceFill(['severity' => 'info'])->save(),
            'impact' => fn () => IncidentImpact::query()->create(['incident_id' => $this->b['incident']->id, 'resource_type' => 'server', 'resource_id' => $this->b['server']->id, 'status' => 'failing']),
            'dependency' => fn () => ResourceDependency::query()->withoutGlobalScopes()->findOrFail($this->b['dependency']->id)->forceFill(['label' => 'x'])->save(),
            'server' => fn () => Server::query()->withoutGlobalScopes()->findOrFail($this->b['server']->id)->forceFill(['name' => 'bravo-2'])->save(),
            'service' => fn () => ServiceCheck::query()->withoutGlobalScopes()->findOrFail($this->b['service']->id)->forceFill(['is_enabled' => false])->save(),
            'domain' => fn () => Domain::query()->withoutGlobalScopes()->findOrFail($this->b['domain']->id)->forceFill(['is_active' => false])->save(),
            'page' => fn () => MonitoredPage::query()->withoutGlobalScopes()->findOrFail($this->b['page']->id)->forceFill(['approval_status' => 'rejected'])->save(),
            'restore' => fn () => Server::query()->withoutGlobalScopes()->withTrashed()->findOrFail($this->b['trashed']->id)->restore(),
        ];

        foreach ($changes as $label => $change) {
            $warm();
            $change();
            $this->assertTrue(Cache::has($aKey), "{$label} of team B cleared team A");
            $this->assertFalse(Cache::has($bKey), "{$label} of team B did not clear team B");
        }

        // Monitoring-only updates do not clear the entry.
        $warm();
        Server::query()->withoutGlobalScopes()->findOrFail($this->b['server']->id)->forceFill(['last_seen_at' => now()->subHour(), 'status' => 'offline'])->save();
        Domain::query()->withoutGlobalScopes()->findOrFail($this->b['domain']->id)->forceFill(['status' => 'critical'])->save();
        $this->assertTrue(Cache::has($bKey));
    }

    public function test_an_entry_rebuilt_during_a_transaction_is_cleared_after_the_commit(): void
    {
        $key = TopologyBuilder::cacheKey($this->a['team']->id);

        DB::transaction(function () use ($key) {
            Incident::query()->withoutGlobalScopes()->findOrFail($this->a['incident']->id)->forceFill(['severity' => 'info'])->save();
            // Another request rebuilds the entry before the commit.
            app(TopologyBuilder::class)->forTeam($this->a['team']->id);
            $this->assertTrue(Cache::has($key));
        });

        $this->assertFalse(Cache::has($key));
    }

    public function test_without_a_tenant_nothing_is_built(): void
    {
        Tenant::forget();

        try {
            app(NexusController::class)->graph(request(), app(TopologyBuilder::class));
            $this->fail('Expected a 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse(Cache::has(TopologyBuilder::cacheKey(0)));
    }

    public function test_the_json_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertOk();
        }

        $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertTooManyRequests();
    }
}
