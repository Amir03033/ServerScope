<?php

namespace Tests\Feature\Nexus;

use App\Models\Team;
use App\Monitoring\Dns\Resolver;
use App\Services\Nexus\TopologyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class NexusPageTest extends TestCase
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

    private function config(string $html): array
    {
        $this->assertMatchesRegularExpression('#<script type="application/json" id="nexus-config">(.*?)</script>#s', $html);
        preg_match('#<script type="application/json" id="nexus-config">(.*?)</script>#s', $html, $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_team_member_sees_nexus_in_the_navigation(): void
    {
        foreach (['member', 'admin'] as $role) {
            $this->actingAs($this->a[$role])->get(route('dashboard'))
                ->assertOk()
                ->assertSee('href="'.route('nexus.index').'" class="ss-nav-item"', false)
                ->assertSee('#i-nexus', false);
        }

        $html = $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('nexus.index'), '#').'"\s+class="ss-nav-item"\s+aria-current="page"#', $html);

        // The globe has its own item under NEXUS; on the globe page only that one is current.
        $html = $this->actingAs($this->a['member'])->get(route('nexus.globe'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('nexus.globe'), '#').'"\s+class="ss-nav-item ss-nav-sub"\s+aria-current="page"#', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('nexus.index'), '#').'"\s+class="ss-nav-item"\s*>#', $html);
    }

    public function test_guests_cannot_open_the_page(): void
    {
        $this->get(route('nexus.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_loads_the_separate_nexus_bundle_and_its_configuration(): void
    {
        $html = $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/build/assets/nexus-[^"]+\.js"#', $html);
        // The chart bundle stays on the pages with charts.
        $this->assertStringNotContainsString('/build/assets/charts-', $html);

        $config = $this->config($html);
        $this->assertSame(route('nexus.graph'), $config['urls']['graph']);
        $this->assertSame(route('incidents.index'), $config['urls']['incidents']);

        foreach (['data-nexus-canvas', 'data-nexus-panel', 'data-nexus-types', 'data-nexus-health', 'data-nexus-incidents', 'data-nexus-live', 'data-nexus-list', 'data-nexus-pause'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
    }

    public function test_the_3d_view_is_offered_but_only_loaded_on_demand(): void
    {
        foreach (['en' => '3D map of servers', 'nl' => '3D-kaart van servers'] as $locale => $label) {
            $this->a['member']->forceFill(['locale' => $locale])->save();
            $html = $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk()->getContent();

            $this->assertStringContainsString('data-nexus-3d ', $html);
            $this->assertStringContainsString('data-nexus-3d-stage', $html);
            $this->assertStringContainsString($label, $html);
            // three.js is its own chunk, fetched when someone opens the 3D view.
            $this->assertStringNotContainsString('/build/assets/scene3d-', $html);

            $config = $this->config($html);
            $this->assertSame(trans('nexus.js.states.webgl_text', [], $locale), $config['texts']['states']['webgl_text']);
            $this->assertSame('3D', $config['texts']['toolbar']['view_3d']);
        }
    }

    public function test_the_page_and_its_script_texts_follow_the_language(): void
    {
        foreach (['en' => ['Active incidents', 'Incident view', 'runs on', 'Online', 'Critical'], 'nl' => ['Actieve incidenten', 'Incidentweergave', 'draait op', 'Online', 'Kritiek']] as $locale => [$stat, $toolbar, $relation, $status, $severity]) {
            $this->a['member']->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk();
            $response->assertSee('<html lang="'.$locale.'">', false)->assertSee($stat)->assertSee($toolbar);

            $config = $this->config($response->getContent());
            $this->assertSame($relation, $config['texts']['relations']['runs_on']);
            $this->assertSame($status, $config['labels']['status']['server']['online']);
            $this->assertSame($severity, $config['labels']['severity']['critical']);
            $this->assertSame(__('nexus.js.pages_other', [], $locale), $config['texts']['pages_other']);
        }
    }

    public function test_the_topology_json_is_the_same_in_every_language(): void
    {
        $bodies = [];

        foreach (['en', 'nl'] as $locale) {
            Cache::flush();
            $this->travelTo(now()->startOfMinute());
            $this->a['member']->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($this->a['member'])->getJson(route('nexus.graph'))->assertOk();
            $bodies[$locale] = json_decode($response->getContent(), true);
            $this->travelBack();
        }

        $this->assertSame($bodies['en'], $bodies['nl']);

        // Only names and codes: structural edges and page groups carry no text.
        $structural = collect($bodies['en']['data']['edges'])->where('origin', 'structural');
        $this->assertNotEmpty($structural);
        $this->assertSame([null], $structural->pluck('label')->unique()->values()->all());
        $this->assertSame([null], collect($bodies['en']['data']['nodes'])->where('type', 'page_group')->pluck('label')->unique()->values()->all());
    }

    public function test_the_summary_is_rendered_on_the_server_for_the_own_team_only(): void
    {
        $this->b['incident']->forceFill(['status' => 'open'])->save();

        $response = $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk();
        $topology = app(TopologyBuilder::class)->forTeam($this->a['team']->id);

        $response->assertSee('data-kpi="nodes">'.$topology['meta']['counts']['nodes'].'<', false);
        $response->assertSee('data-kpi="incidents">'.$topology['meta']['counts']['active_incidents'].'<', false);
        $response->assertSee('data-kpi="edges">'.$topology['meta']['counts']['edges'].'<', false);
        $this->assertStringNotContainsStringIgnoringCase('bravo', $response->getContent());
    }

    public function test_an_empty_team_still_gets_the_page(): void
    {
        $team = Team::query()->create(['name' => 'Leeg', 'slug' => 'leeg']);
        $user = $this->member($team, 'member');

        $this->actingAs($user)->get(route('nexus.index'))
            ->assertOk()
            ->assertSee('data-kpi="nodes">0<', false);
    }

    public function test_the_skyline_and_the_heartbeat_show_only_the_teams_own_servers(): void
    {
        // Skyline: its own menu item under NEXUS, the team's topology, its own script.
        $html = $this->actingAs($this->a['member'])->get(route('nexus.skyline'))->assertOk()
            ->assertSee('id="skyline-config"', false)
            ->assertSee('alpha-server')
            ->assertDontSee('bravo')
            ->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('nexus.skyline'), '#').'"\s+class="ss-nav-item ss-nav-sub"\s+aria-current="page"#', $html);
        $this->assertMatchesRegularExpression('#src="[^"]*/build/assets/skyline-[^"]+\.js"#', $html);
        $this->get(route('nexus.skyline'))->assertOk();
        auth()->logout();
        $this->get(route('nexus.skyline'))->assertRedirect(route('login'));

        // Dashboard heartbeat: one row per own server, data from the team's graph.
        $html = $this->actingAs($this->b['member'])->get(route('dashboard'))->assertOk()
            ->assertSee('data-pulse-row="server:'.$this->b['server']->id.'"', false)
            ->assertDontSee('data-pulse-row="server:'.$this->a['server']->id.'"', false)
            ->assertSee('id="pulse-config"', false)
            ->getContent();
        $this->assertMatchesRegularExpression('#src="[^"]*/build/assets/pulse-[^"]+\.js"#', $html);
        $this->assertStringContainsString(str_replace('/', '\\/', route('nexus.graph')), $html);
    }
}
