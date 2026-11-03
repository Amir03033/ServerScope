<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Monitoring\Dns\Resolver;
use App\Services\Nexus\TopologyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** The live logo and tab icon: the team's own pulse, nothing from other teams. */
class LivePulseTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, new FakeResolver);
        $this->createTwoTeams();
        Incident::query()->withoutGlobalScopes()->update(['status' => 'resolved']);
    }

    public function test_the_pulse_follows_the_teams_own_incidents_and_servers(): void
    {
        $this->get(route('live.pulse'))->assertRedirect(route('login'));

        $calm = $this->actingAs($this->a['member'])->getJson(route('live.pulse'))->assertOk()->json('data');
        $this->assertSame(['state' => 'ok', 'incidents' => 0, 'critical' => 0, 'offline' => 0], array_intersect_key($calm, array_flip(['state', 'incidents', 'critical', 'offline'])));
        $this->assertGreaterThanOrEqual(50, $calm['bpm']);

        // A critical incident of team B is not team A's.
        Incident::query()->withoutGlobalScopes()->whereKey($this->b['incident']->id)->update(['status' => 'open', 'severity' => 'critical']);
        $this->assertSame(0, $this->actingAs($this->a['member'])->getJson(route('live.pulse'))->json('data.incidents'));
        $b = $this->actingAs($this->b['member'])->getJson(route('live.pulse'))->json('data');
        $this->assertSame(['critical', 1, 1], [$b['state'], $b['incidents'], $b['critical']]);

        // An offline server is critical too.
        \App\Models\Server::query()->withoutGlobalScopes()->whereKey($this->a['server']->id)->update(['last_seen_at' => now()->subDay(), 'status' => 'offline']);
        TopologyBuilder::forget($this->a['team']->id);
        $a = $this->actingAs($this->a['member'])->getJson(route('live.pulse'))->json('data');
        $this->assertSame(['critical', 1], [$a['state'], $a['offline']]);
    }

    public function test_every_page_has_the_live_logo_and_icon(): void
    {
        $html = $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="ss-live" content="'.route('live.pulse').'">', $html);
        $this->assertStringContainsString('data-live-icon', $html);
        $this->assertStringContainsString('ss-live-logo', $html);
    }
}
