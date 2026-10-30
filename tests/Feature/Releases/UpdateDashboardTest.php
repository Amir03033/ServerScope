<?php

namespace Tests\Feature\Releases;

use App\Models\AgentUpdateRequest;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignedReleases;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class UpdateDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;
    use TwoTeams;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTwoTeams();
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        $this->platform = User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
        $own = \App\Models\Team::query()->create(['name' => 'Platform', 'slug' => 'platform']);
        $this->platform->teams()->attach($own->id, ['role' => 'owner']);
        $this->platform->forceFill(['current_team_id' => $own->id])->save();
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle('0.3.1', $key), '--admin' => 'platform@example.test'])->assertSuccessful();

        foreach ([$this->a['server'], $this->b['server']] as $server) {
            $this->withUpdater($server);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function withUpdater(Server $server, string $version = '0.3.0', string $state = 'enabled'): void
    {
        $server->forceFill(['agent_version' => $version, 'updater_version' => 1, 'updater_state' => $state, 'updater_last_seen_at' => now()])->save();
    }

    public function test_the_server_page_shows_versions_and_updater_state(): void
    {
        $this->actingAs($this->a['member'])->get(route('servers.show', $this->a['server']))->assertOk()
            ->assertSee('Agentupdates')
            ->assertSee('0.3.0')
            ->assertSee('0.3.1')
            ->assertSee('Updateprogramma actief')
            ->assertDontSee('Bijwerken naar 0.3.1');

        $this->actingAs($this->a['admin'])->get(route('servers.show', $this->a['server']))->assertOk()->assertSee('Bijwerken naar 0.3.1');

        // A 0.2.0 server without the updater explains how to get it.
        $this->a['server']->forceFill(['agent_version' => '0.2.0', 'updater_last_seen_at' => null, 'updater_state' => null, 'updater_version' => null])->save();
        $this->actingAs($this->a['admin'])->get(route('servers.show', $this->a['server']))->assertOk()
            ->assertSee('Updateprogramma niet gevonden')
            ->assertSee('install.sh opnieuw');

        $this->withUpdater($this->a['server'], '0.3.0', 'disabled');
        $this->actingAs($this->a['admin'])->get(route('servers.show', $this->a['server']))->assertOk()->assertSee('lokaal uitgeschakeld');
    }

    public function test_a_team_administrator_can_update_an_own_server_and_it_is_audited(): void
    {
        $this->actingAs($this->a['admin'])->post(route('servers.agent-update', $this->a['server']))->assertRedirect()->assertSessionHas('success');

        $request = AgentUpdateRequest::withoutGlobalScopes()->sole();
        $this->assertSame([$this->a['server']->id, '0.3.1', '0.3.0', 'pending', 'manual', $this->a['admin']->id], [$request->server_id, $request->target_version, $request->from_version, $request->status, $request->source, $request->requested_by]);

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'agent_update.requested')->sole();
        $this->assertSame([$this->a['team']->id, $this->a['admin']->id, '0.3.1', '0.3.0'], [$entry->team_id, $entry->user_id, $entry->properties['version'], $entry->properties['from']]);

        // A second click does not create a second request.
        $this->actingAs($this->a['admin'])->post(route('servers.agent-update', $this->a['server']))->assertRedirect();
        $this->assertSame(1, AgentUpdateRequest::withoutGlobalScopes()->count());

        $this->actingAs($this->a['member'])->get(route('servers.show', $this->a['server']))->assertSee('Wachtend');
    }

    public function test_another_team_gets_404_and_a_member_403(): void
    {
        $this->actingAs($this->a['admin'])->post(route('servers.agent-update', $this->b['server']))->assertNotFound();
        $this->actingAs($this->a['admin'])->put(route('servers.auto-update', $this->b['server']), ['enabled' => '1'])->assertNotFound();
        $this->actingAs($this->a['member'])->post(route('servers.agent-update', $this->b['server']))->assertNotFound();

        $this->actingAs($this->a['member'])->post(route('servers.agent-update', $this->a['server']))->assertForbidden();
        $this->actingAs($this->a['member'])->put(route('servers.auto-update', $this->a['server']), ['enabled' => '1'])->assertForbidden();
        $this->actingAs($this->a['member'])->post(route('servers.agent-update-all'))->assertForbidden();

        $this->assertSame(0, AgentUpdateRequest::withoutGlobalScopes()->count());
        $this->assertFalse($this->b['server']->fresh()->auto_update_enabled);
        $this->assertFalse($this->a['server']->fresh()->auto_update_enabled);
    }

    public function test_update_all_only_touches_eligible_servers_of_the_own_team(): void
    {
        $current = Server::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-actueel', 'hostname' => 'actueel.alpha.test']);
        $this->withUpdater($current, '0.3.1');
        $noUpdater = Server::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-oud', 'hostname' => 'oud.alpha.test', 'agent_version' => '0.2.0']);

        $this->actingAs($this->a['admin'])->get(route('servers.index'))->assertOk()->assertSee('Alles bijwerken naar 0.3.1');
        $this->actingAs($this->a['member'])->get(route('servers.index'))->assertOk()->assertDontSee('Alles bijwerken');

        $this->actingAs($this->a['admin'])->post(route('servers.agent-update-all'))->assertRedirect()->assertSessionHas('success');

        $requests = AgentUpdateRequest::withoutGlobalScopes()->get();
        $this->assertSame([$this->a['server']->id], $requests->pluck('server_id')->all());
        $this->assertSame('bulk', $requests->first()->source);
        $this->assertSame(0, AgentUpdateRequest::withoutGlobalScopes()->whereIn('server_id', [$this->b['server']->id, $current->id, $noUpdater->id])->count());
    }

    public function test_automatic_updates_are_off_by_default_and_toggled_by_administrators(): void
    {
        $this->assertFalse($this->a['server']->fresh()->auto_update_enabled);

        $this->actingAs($this->a['admin'])->put(route('servers.auto-update', $this->a['server']), ['enabled' => '1'])->assertRedirect();
        $this->assertTrue($this->a['server']->fresh()->auto_update_enabled);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'agent_update.auto_enabled')->where('team_id', $this->a['team']->id)->count());

        $this->actingAs($this->a['admin'])->put(route('servers.auto-update', $this->a['server']), ['enabled' => '0'])->assertRedirect();
        $this->assertFalse($this->a['server']->fresh()->auto_update_enabled);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'agent_update.auto_disabled')->count());
    }

    public function test_an_update_is_refused_when_the_server_cannot_take_it(): void
    {
        $this->withUpdater($this->a['server'], '0.3.1');
        $this->actingAs($this->a['admin'])->post(route('servers.agent-update', $this->a['server']))->assertRedirect()->assertSessionHas('error');

        $this->withUpdater($this->a['server'], '0.3.0', 'disabled');
        $this->actingAs($this->a['admin'])->post(route('servers.agent-update', $this->a['server']))->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AgentUpdateRequest::withoutGlobalScopes()->count());
    }

    public function test_the_release_overview_is_for_platform_administrators_only(): void
    {
        $this->actingAs($this->a['admin'])->get(route('platform.agent-releases'))->assertForbidden();
        $this->actingAs($this->a['member'])->get(route('platform.agent-releases'))->assertForbidden();

        $response = $this->actingAs($this->platform)->get(route('platform.agent-releases'))->assertOk()
            ->assertSee('0.3.1')
            ->assertSee('0.3.0');

        // Counts only: no names of servers or teams.
        $response->assertDontSee('alpha-server')->assertDontSee('bravo-server')->assertDontSee('Bravo BV');
        $this->assertSame(2, $response->viewData('versions')->firstWhere('version', '0.3.0')['servers']);
    }
}
