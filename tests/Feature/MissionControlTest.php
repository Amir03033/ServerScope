<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\Server;
use App\Monitoring\Dns\Resolver;
use App\Services\Live\MissionControl;
use App\Services\Nexus\TopologyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Mission control on the dashboard: an explainable score, the team's own tiles and timeline. */
class MissionControlTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, new FakeResolver);
        $this->createTwoTeams();
        Incident::query()->withoutGlobalScopes()->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    private function mission(array $team): array
    {
        TopologyBuilder::forget($team['team']->id);

        return app(MissionControl::class)->forTeam($team['team']->id);
    }

    public function test_the_score_is_100_minus_the_weight_of_every_listed_problem(): void
    {
        $calm = $this->mission($this->a);
        $this->assertSame([], $calm['issues']);

        // A critical incident and an offline server cost their weights, and are listed.
        Incident::query()->withoutGlobalScopes()->whereKey($this->a['incident']->id)->update(['status' => 'open', 'severity' => 'critical']);
        Server::query()->withoutGlobalScopes()->whereKey($this->a['server']->id)->update(['last_seen_at' => now()->subDay(), 'status' => 'offline']);
        $m = $this->mission($this->a);

        $this->assertSame(['incident_major', 'server_offline'], array_slice(array_column($m['issues'], 'key'), 0, 2));
        $expected = 100 - array_sum(array_map(fn ($i) => MissionControl::WEIGHTS[$i['key']] * $i['count'], $m['issues']));
        $this->assertSame(max(0, $expected), $m['score']);
        $this->assertLessThan($calm['score'], $m['score']);
        $this->assertStringStartsWith(__('mission.headline.attention', ['issue' => '']), $m['headline']);
    }

    public function test_tiles_and_timeline_are_the_teams_own(): void
    {
        Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->b['team']->id, 'application' => 'bravo-app', 'version' => '2.0', 'deployed_at' => now()->subHour()]);
        Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->a['team']->id, 'application' => 'alpha-app', 'version' => '1.0', 'deployed_at' => now()->subHour()]);
        Incident::query()->withoutGlobalScopes()->whereKey($this->b['incident']->id)->update(['status' => 'open', 'opened_at' => now()->subHour()]);

        $m = $this->mission($this->a);

        $this->assertContains('server:'.$this->a['server']->id, array_column($m['tiles'], 'key'));
        $this->assertStringNotContainsString('bravo', json_encode($m));
        $this->assertContains('alpha-app 1.0', array_column($m['timeline']['events'], 'label'));

        // Through HTTP: the JSON follows the signed-in team; guests are sent to the login.
        $this->get(route('dashboard.mission'))->assertRedirect(route('login'));
        $json = $this->actingAs($this->b['member'])->getJson(route('dashboard.mission'))->assertOk()->json('data');
        $this->assertContains('bravo-app 2.0', array_column($json['timeline']['events'], 'label'));
        $this->assertStringNotContainsString('alpha', json_encode($json));
    }

    public function test_the_dashboard_starts_with_mission_control_and_keeps_the_details(): void
    {
        $html = $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()
            ->assertSee('data-mission', false)
            ->assertSee('data-widgets', false)
            ->assertSee('alpha-server')
            ->getContent();

        $this->assertMatchesRegularExpression('#src="[^"]*/build/assets/mission-[^"]+\.js"#', $html);
        $this->assertLessThan(strpos($html, 'data-widget="attention"'), strpos($html, 'data-widget="health"'), 'mission control comes first, the dashboard as before follows');
    }
}
