<?php

namespace Tests\Feature;

use App\Monitoring\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Command palette: only pages this person can open, filters on the existing lists, no data. */
class PaletteTest extends TestCase
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

    private function commands($user): array
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="palette-config">(.*?)</script>#s', $html, $match);

        return array_column(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['commands'], null, 'id');
    }

    public function test_commands_follow_the_menu_and_the_role(): void
    {
        $member = $this->commands($this->a['member']);
        $admin = $this->commands($this->a['admin']);

        $this->assertArrayHasKey('go:nexus.skyline', $member, 'NEXUS pages are in the palette');
        $this->assertArrayHasKey('go:servers.index', $member);
        $this->assertArrayNotHasKey('go:users.index', $member, 'admin pages only for admins');
        $this->assertArrayNotHasKey('do:add_server', $member);
        $this->assertArrayHasKey('go:users.index', $admin);
        $this->assertArrayHasKey('do:add_server', $admin);

        // Quick filters open the existing lists with their filter; view actions have no URL.
        $this->assertSame(route('servers.index', ['status' => 'offline']), $member['filter:servers_offline']['url']);
        $this->assertSame(route('domains.index', ['sort' => 'response']), $member['filter:sites_slow']['url']);
        $this->assertArrayNotHasKey('url', $member['view:theme']);

        $this->actingAs($this->a['member'])->get($member['filter:servers_offline']['url'])->assertOk();
        $this->actingAs($this->a['member'])->get($member['filter:incidents_unacknowledged']['url'])->assertOk();
    }

    public function test_the_lists_refresh_themselves_and_other_pages_do_not(): void
    {
        foreach (['servers.index', 'domains.index', 'incidents.index', 'alerts.index'] as $route) {
            $this->actingAs($this->a['member'])->get(route($route))->assertOk()->assertSee('<meta name="ss-live-list" content="30">', false);
        }

        $this->actingAs($this->a['member'])->get(route('dashboard'))->assertOk()->assertDontSee('name="ss-live-list"', false);
    }
}
