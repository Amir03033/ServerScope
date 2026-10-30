<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignedReleases;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * "Needs attention" on the dashboard, including the agent update count
 * (display data from DashboardController, scoped to the current team).
 */
class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTwoTeams();
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function publishRelease(string $version = '0.3.1'): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle($version, $key), '--admin' => 'platform@example.test'])->assertSuccessful();
    }

    private function agent(Server $server, string $version, bool $updater = true): void
    {
        $server->forceFill([
            'agent_version' => $version,
            'updater_version' => $updater ? 1 : null,
            'updater_state' => $updater ? 'enabled' : null,
            'updater_last_seen_at' => $updater ? now() : null,
            'last_seen_at' => now(),
        ])->save();
    }

    public function test_outdated_agents_are_counted_for_the_own_team_only(): void
    {
        $this->publishRelease();
        $this->agent($this->a['server'], '0.3.0');
        $without = Server::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-zonder-updater', 'hostname' => 'alpha2.host.test', 'last_seen_at' => now()]);
        $this->agent($without, '0.2.0', updater: false);
        $current = Server::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-actueel', 'hostname' => 'alpha3.host.test', 'last_seen_at' => now()]);
        $this->agent($current, '0.3.1');

        // Team B has more outdated servers; they must not count for team A.
        $this->agent($this->b['server'], '0.3.0');
        foreach (range(1, 3) as $i) {
            $this->agent(Server::forceCreate(['team_id' => $this->b['team']->id, 'name' => "bravo-{$i}", 'hostname' => "bravo{$i}.host.test"]), '0.1.0');
        }

        $this->actingAs($this->a['admin'])->get(route('dashboard'))->assertOk()
            ->assertViewHas('agentUpdates', ['version' => '0.3.1', 'outdated' => 2, 'ready' => 1])
            ->assertSee('servers met een oudere agent')
            ->assertSee('Nieuwste versie 0.3.1 · 1 klaar om bij te werken')
            ->assertDontSee('bravo');

        $this->actingAs($this->b['admin'])->get(route('dashboard'))->assertOk()
            ->assertViewHas('agentUpdates', ['version' => '0.3.1', 'outdated' => 4, 'ready' => 4]);
    }

    public function test_no_update_item_without_a_release_or_outdated_agents(): void
    {
        $this->agent($this->a['server'], '0.3.0');

        $this->actingAs($this->a['admin'])->get(route('dashboard'))->assertOk()
            ->assertViewHas('agentUpdates', ['version' => null, 'outdated' => 0, 'ready' => 0])
            ->assertDontSee('oudere agent');
    }

    public function test_attention_links_to_the_filtered_lists(): void
    {
        $this->a['server']->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->a['incident']->forceFill(['status' => 'open'])->save();

        $this->actingAs($this->a['admin'])->get(route('dashboard'))->assertOk()
            ->assertSee('Aandacht nodig')
            ->assertSee('href="'.route('servers.index', ['status' => 'offline']).'"', false)
            ->assertSee('server offline')
            ->assertSee('open incident')
            ->assertDontSee('Alles in orde');
    }

    public function test_all_good_when_nothing_needs_attention(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Alles in orde')
            ->assertSee('Aan de slag');
    }

    public function test_the_dashboard_is_english_by_default(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        app()->setLocale('en');

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee(['Needs attention', 'All good', 'Get started', 'Active incidents', 'Expiring soon', 'Recent DNS changes', 'Server performance', 'All servers', 'Recent measurements'])
            ->assertDontSee(['Aandacht nodig', 'Verloopt binnenkort', 'Totaal servers', 'Recente metingen']);
    }
}
