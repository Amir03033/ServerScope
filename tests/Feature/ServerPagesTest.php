<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rebuilt server pages: section navigation with working anchors,
 * dangerous actions grouped under "Manage", and both languages.
 */
class ServerPagesTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test', 'last_seen_at' => now()]);
    }

    public function test_every_section_link_points_at_exactly_one_target(): void
    {
        $server = $this->server();
        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('servers.show', $server))->assertOk()->getContent();

        foreach (['overview', 'metrics', 'system', 'services', 'alerts', 'agent-updates', 'manage', 'credentials'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "id=\"{$id}\" must exist once");
        }

        preg_match('/<nav class="ss-section-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/href="#([a-z-]+)"/', $nav[1], $links);
        $this->assertSame(['overview', 'metrics', 'system', 'services', 'alerts', 'agent-updates', 'manage'], $links[1]);
    }

    public function test_manage_and_its_dangerous_actions_are_for_admins_only(): void
    {
        $server = $this->server();

        $admin = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('servers.show', $server))->assertOk()->getContent();
        $manage = strpos($admin, 'id="manage"');
        $this->assertNotFalse($manage);
        $this->assertGreaterThan($manage, strpos($admin, route('servers.credentials.rotate', $server)));
        $this->assertGreaterThan($manage, strpos($admin, route('servers.credentials.store', $server)));

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('servers.show', $server))->assertOk()
            ->assertDontSee('id="manage"', false)
            ->assertDontSee('href="#manage"', false)
            ->assertDontSee(route('servers.credentials.rotate', $server));
    }

    public function test_restore_and_permanent_delete_of_a_deleted_server_are_under_manage(): void
    {
        $server = $this->server();
        $server->delete();

        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('servers.show', $server))->assertOk()
            ->assertSee('Deze server is verwijderd')
            ->getContent();

        $manage = strpos($html, 'id="manage"');
        $this->assertGreaterThan($manage, strpos($html, route('servers.restore', $server)));
        $this->assertGreaterThan($manage, strpos($html, route('servers.force-destroy', $server)));
        $this->assertStringContainsString('name="confirm_name"', $html);
    }

    public function test_server_pages_are_english_by_default(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $server = Server::create(['name' => 'new-01', 'hostname' => 'new-01.example.test']);
        app()->setLocale('en');

        $this->actingAs($user)->get(route('servers.show', $server))->assertOk()
            ->assertSee(['Performance monitoring', 'Disk usage', 'System information', 'Recent alerts', 'Agent updates', 'Manage', 'Agent credentials'])
            ->assertSee('Diagnosis: Waiting for the first measurement')
            ->assertDontSee(['Prestatiemonitoring', 'Schijfgebruik', 'Systeeminformatie', 'Agentupdates', 'Wacht op eerste meting']);

        $this->actingAs($user)->get(route('servers.index'))->assertOk()
            ->assertSee(['All monitored servers', 'Add server', 'Pending'])
            ->assertDontSee(['Alle gemonitorde servers', 'In afwachting']);

        config(['app.url' => 'https://monitor.example.test']);
        $this->actingAs($user)->get(route('servers.add'))->assertOk()->assertSee('Create enrolment token');
        $this->actingAs($user)->get(route('servers.edit', $server))->assertOk()->assertSee(['Edit server', 'Delete server', 'Maintenance window']);
    }

    public function test_the_mobile_cards_have_labels_for_every_value_cell(): void
    {
        $this->server();
        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('servers.index'))->assertOk()->getContent();

        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
        preg_match_all('/<td([^>]*)>/', $body[1], $cells);

        foreach ($cells[1] as $attributes) {
            $this->assertTrue(str_contains($attributes, 'data-label=') || str_contains($attributes, 'is-primary') || str_contains($attributes, 'is-actions'), "Cell without a label: <td{$attributes}>");
        }
    }
}
