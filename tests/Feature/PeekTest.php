<?php

namespace Tests\Feature;

use App\Monitoring\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Side panel and hover cards: a read-only look at the team's own servers, websites and incidents. */
class PeekTest extends TestCase
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

    public function test_a_member_peeks_at_the_teams_own_resources(): void
    {
        $server = $this->actingAs($this->a['member'])->getJson(route('peek.server', $this->a['server']))->assertOk()->json('data');
        $this->assertSame(['server', 'alpha-server', route('servers.show', $this->a['server'])], [$server['type'], $server['title'], $server['url']]);
        $this->assertContains('alpha-nginx', array_column($server['sections'][array_search(__('peek.services'), array_column($server['sections'], 'title'))]['items'], 'label'));

        $domain = $this->actingAs($this->a['member'])->getJson(route('peek.domain', $this->a['domain']))->assertOk()->json('data');
        $this->assertSame('domain', $domain['type']);
        $this->assertCount(4, $domain['stats']);

        $incident = $this->actingAs($this->a['member'])->getJson(route('peek.incident', $this->a['incident']))->assertOk()->json('data');
        $this->assertSame([$this->a['incident']->title, route('incidents.show', $this->a['incident'])], [$incident['title'], $incident['url']]);

        foreach ([$server, $domain, $incident] as $data) {
            $this->assertStringNotContainsString('bravo', json_encode($data));
        }
    }

    public function test_another_teams_resources_do_not_exist_and_guests_must_log_in(): void
    {
        $this->actingAs($this->a['member'])->getJson(route('peek.server', $this->b['server']))->assertNotFound();
        $this->actingAs($this->a['member'])->getJson(route('peek.domain', $this->b['domain']))->assertNotFound();
        $this->actingAs($this->a['member'])->getJson(route('peek.incident', $this->b['incident']))->assertNotFound();

        auth()->logout();
        $this->get(route('peek.server', $this->a['server']))->assertRedirect(route('login'));
    }

    public function test_every_page_knows_where_to_peek_and_the_heartbeat_follows_mission_control(): void
    {
        $html = $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="ss-peek" content="'.url('/peek').'">', $html);
        $this->assertLessThan(strpos($html, 'data-pulse '), strpos($html, 'data-mission '), 'mission control first');
        $this->assertLessThan(strpos($html, 'data-widget="attention"'), strpos($html, 'data-pulse '), 'the heartbeat above the details');
    }
}
