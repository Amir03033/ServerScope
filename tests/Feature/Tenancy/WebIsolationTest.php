<?php

namespace Tests\Feature\Tenancy;

use App\Models\Incident;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\StatusPage;
use App\Monitoring\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * A signed-in administrator of team A must never see, change or even
 * detect data of team B through the web interface.
 */
class WebIsolationTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->app->instance(Resolver::class, (new FakeResolver)->host('hooks.example.com', '93.184.215.50')->host('bravo-shop.example.com', '93.184.215.51'));
        $this->createTwoTeams();
    }

    /**
     * Every route that takes an ID, called by the owner of team A with an
     * ID of team B: 404 (never 403, which would reveal that it exists).
     *
     * @return array<string, array{0: string, 1: string, 2: callable(array): array}>
     */
    private function foreignRoutes(): array
    {
        $b = $this->b;

        return [
            'servers.show' => ['get', 'servers.show', fn () => [$b['server']]],
            'servers.show trashed' => ['get', 'servers.show', fn () => [$b['trashed']->id]],
            'servers.edit' => ['get', 'servers.edit', fn () => [$b['server']]],
            'servers.update' => ['put', 'servers.update', fn () => [$b['server']]],
            'servers.destroy' => ['delete', 'servers.destroy', fn () => [$b['server']]],
            'servers.restore' => ['post', 'servers.restore', fn () => [$b['trashed']->id]],
            'servers.force-destroy' => ['delete', 'servers.force-destroy', fn () => [$b['trashed']->id]],
            'servers.maintenance.start' => ['post', 'servers.maintenance.start', fn () => [$b['server']]],
            'servers.maintenance.end' => ['delete', 'servers.maintenance.end', fn () => [$b['server']]],
            'servers.credentials.store' => ['post', 'servers.credentials.store', fn () => [$b['server']]],
            'servers.credentials.rotate' => ['post', 'servers.credentials.rotate', fn () => [$b['server']]],
            'servers.credentials.destroy' => ['delete', 'servers.credentials.destroy', fn () => [$b['server'], $b['credential']]],
            'services.update' => ['put', 'services.update', fn () => [$b['service']]],
            'services.destroy' => ['delete', 'services.destroy', fn () => [$b['service']]],
            'domains.show' => ['get', 'domains.show', fn () => [$b['domain']]],
            'domains.edit' => ['get', 'domains.edit', fn () => [$b['domain']]],
            'domains.update' => ['put', 'domains.update', fn () => [$b['domain']]],
            'domains.destroy' => ['delete', 'domains.destroy', fn () => [$b['domain']]],
            'domains.pause' => ['post', 'domains.pause', fn () => [$b['domain']]],
            'domains.resume' => ['post', 'domains.resume', fn () => [$b['domain']]],
            'domains.check' => ['post', 'domains.check', fn () => [$b['domain']]],
            'domains.restore' => ['post', 'domains.restore', fn () => [$b['domain']]],
            'domains.force-destroy' => ['delete', 'domains.force-destroy', fn () => [$b['domain']]],
            'websites.pages' => ['get', 'websites.pages', fn () => [$b['domain']]],
            'websites.pages.create' => ['get', 'websites.pages.create', fn () => [$b['domain']]],
            'websites.pages.store' => ['post', 'websites.pages.store', fn () => [$b['domain']]],
            'websites.pages.bulk' => ['post', 'websites.pages.bulk', fn () => [$b['domain']]],
            'websites.discovery' => ['get', 'websites.discovery', fn () => [$b['domain']]],
            'websites.discovery.update' => ['put', 'websites.discovery.update', fn () => [$b['domain']]],
            'websites.discovery.run' => ['post', 'websites.discovery.run', fn () => [$b['domain']]],
            'pages.show' => ['get', 'pages.show', fn () => [$b['page']]],
            'pages.edit' => ['get', 'pages.edit', fn () => [$b['page']]],
            'pages.update' => ['put', 'pages.update', fn () => [$b['page']]],
            'pages.destroy' => ['delete', 'pages.destroy', fn () => [$b['page']]],
            'pages.pause' => ['post', 'pages.pause', fn () => [$b['page']]],
            'pages.resume' => ['post', 'pages.resume', fn () => [$b['page']]],
            'pages.check' => ['post', 'pages.check', fn () => [$b['page']]],
            'page-changes.acknowledge' => ['post', 'page-changes.acknowledge', fn () => [$b['change']]],
            'incidents.show' => ['get', 'incidents.show', fn () => [$b['incident']]],
            'incidents.transition' => ['post', 'incidents.transition', fn () => [$b['incident']]],
            'incidents.assign' => ['post', 'incidents.assign', fn () => [$b['incident']]],
            'incidents.detach' => ['post', 'incidents.detach', fn () => [$b['incident']]],
            'incidents.severity' => ['post', 'incidents.severity', fn () => [$b['incident']]],
            'incidents.comment' => ['post', 'incidents.comment', fn () => [$b['incident']]],
            'dependencies.destroy' => ['delete', 'dependencies.destroy', fn () => [$b['dependency']]],
            'alert-rules.edit' => ['get', 'alert-rules.edit', fn () => [$b['rule']]],
            'alert-rules.update' => ['put', 'alert-rules.update', fn () => [$b['rule']]],
            'alert-rules.destroy' => ['delete', 'alert-rules.destroy', fn () => [$b['rule']]],
            'status-pages.edit' => ['get', 'status-pages.edit', fn () => [$b['statusPage']]],
            'status-pages.update' => ['put', 'status-pages.update', fn () => [$b['statusPage']]],
            'status-pages.destroy' => ['delete', 'status-pages.destroy', fn () => [$b['statusPage']]],
            'webhooks.show' => ['get', 'webhooks.show', fn () => [$b['webhook']]],
            'webhooks.edit' => ['get', 'webhooks.edit', fn () => [$b['webhook']]],
            'webhooks.update' => ['put', 'webhooks.update', fn () => [$b['webhook']]],
            'webhooks.destroy' => ['delete', 'webhooks.destroy', fn () => [$b['webhook']]],
            'webhooks.secret' => ['post', 'webhooks.secret', fn () => [$b['webhook']]],
            'webhooks.test' => ['post', 'webhooks.test', fn () => [$b['webhook']]],
            'webhooks.redeliver' => ['post', 'webhooks.redeliver', fn () => [$b['webhook'], $b['delivery']]],
            'users.update' => ['put', 'users.update', fn () => [$b['member']]],
            'users.destroy' => ['delete', 'users.destroy', fn () => [$b['member']]],
            'api-tokens.destroy' => ['delete', 'api-tokens.destroy', fn () => [$b['token']]],
        ];
    }

    public function test_every_route_with_an_id_of_another_team_returns_404(): void
    {
        // Soft-deleted resources are only reachable through restore/force routes.
        $this->b['domain']->delete();
        $before = $this->snapshotB();
        $failures = [];

        foreach ($this->foreignRoutes() as $label => [$method, $name, $params]) {
            $response = $this->actingAs($this->a['admin'])->{$method}(route($name, $params()), ['confirm_name' => 'bravo-oud', 'status' => 'acknowledged', 'message' => 'x', 'severity' => 'info', 'assigned_to' => $this->a['member']->id, 'name' => 'x', 'duration_minutes' => 30, 'action' => 'pause', 'ids' => [$this->b['page']->id]]);

            if ($response->status() !== 404) {
                $failures[] = "{$label}: {$response->status()}";
            }
        }

        $this->assertSame([], $failures, 'Routes that did not answer 404 for another team\'s ID');
        $this->assertSame($before, $this->snapshotB(), 'Data of team B changed');
    }

    public function test_a_member_without_admin_rights_also_gets_404_not_403_for_foreign_ids(): void
    {
        foreach (['servers.edit' => [$this->b['server']], 'domains.edit' => [$this->b['domain']], 'webhooks.show' => [$this->b['webhook']], 'status-pages.edit' => [$this->b['statusPage']]] as $name => $params) {
            $this->actingAs($this->a['member'])->get(route($name, $params))->assertNotFound();
        }
    }

    /** @return array<string, mixed> */
    private function snapshotB(): array
    {
        // Compared as JSON so dates compare by value.
        return json_decode(json_encode([
            'server' => $this->b['server']->fresh()?->only(['name', 'deleted_at', 'maintenance_ends_at']),
            'trashed' => Server::withoutGlobalScopes()->withTrashed()->find($this->b['trashed']->id)?->only(['deleted_at']),
            'credentials' => $this->b['server']->agentCredentials()->whereNull('revoked_at')->count(),
            'service' => $this->b['service']->fresh()?->only(['is_enabled', 'display_name']),
            'page' => $this->b['page']->fresh()?->only(['is_active', 'url']),
            'change' => $this->b['change']->fresh()?->only(['acknowledged_at']),
            'incident' => Incident::withoutGlobalScopes()->find($this->b['incident']->id)->only(['status', 'assigned_to', 'severity']),
            'incident_events' => Incident::withoutGlobalScopes()->find($this->b['incident']->id)->events()->count(),
            'dependency' => ResourceDependency::withoutGlobalScopes()->whereKey($this->b['dependency']->id)->exists(),
            'rule' => $this->b['rule']->fresh()?->only(['name', 'is_enabled']),
            'status_page' => StatusPage::withoutGlobalScopes()->find($this->b['statusPage']->id)?->only(['name']),
            'webhook' => $this->b['webhook']->fresh()?->only(['name', 'secret']),
            'deliveries' => $this->b['webhook']->deliveries()->count(),
            'member' => $this->b['member']->fresh()?->only(['is_admin']),
            'token' => $this->b['token']->fresh()->revoked_at,
        ]), true);
    }

    public function test_lists_search_dashboard_and_reports_never_show_another_team(): void
    {
        $this->b['incident']->forceFill(['status' => 'open'])->save();

        $pages = [
            route('dashboard'),
            route('servers.index'),
            route('servers.index', ['status' => 'deleted']),
            route('domains.index'),
            route('incidents.index', ['state' => 'all']),
            route('incidents.create'),
            route('incidents.show', $this->a['incident']),
            route('alerts.index', ['status' => 'all']),
            route('alert-rules.index'),
            route('dependencies.index'),
            route('status-pages.index'),
            route('status-pages.create'),
            route('webhooks.index'),
            route('users.index'),
            route('audit-logs.index'),
            route('notifications.index', ['tab' => 'deliveries']),
            // Terms that match records of both teams.
            route('search', ['q' => 'shop']),
            route('search', ['q' => 'storing']),
            route('search', ['q' => (string) $this->b['incident']->id]),
            route('reports.index'),
            route('reports.print'),
            route('servers.show', $this->a['server']),
            route('domains.show', $this->a['domain']),
            route('websites.pages', $this->a['domain']),
            route('pages.show', $this->a['page']),
            route('system-health'),
            route('api-tokens.index'),
        ];

        foreach ($pages as $url) {
            $response = $this->actingAs($this->a['admin'])->get($url);
            $response->assertOk();
            $this->assertStringNotContainsStringIgnoringCase('bravo', $response->getContent(), "Team B leaked on {$url}");
        }
    }

    public function test_csv_exports_contain_only_own_team(): void
    {
        foreach (['', 'incidents'] as $kind) {
            $response = $this->actingAs($this->a['admin'])->get(route('reports.export', ['kind' => $kind]));
            $response->assertOk();
            $csv = $response->streamedContent();
            $this->assertStringContainsString('alpha', $csv);
            $this->assertStringNotContainsStringIgnoringCase('bravo', $csv, "Team B leaked in {$kind} export");
        }
    }

    public function test_users_of_another_team_cannot_be_assigned(): void
    {
        $incident = $this->a['incident'];

        $this->actingAs($this->a['member'])->post(route('incidents.assign', $incident), ['assigned_to' => $this->b['member']->id])->assertSessionHasErrors('assigned_to');
        $this->assertNull($incident->fresh()->assigned_to);

        $this->actingAs($this->a['admin'])->post(route('incidents.store'), ['title' => 'Handmatig', 'severity' => 'warning', 'assigned_to' => $this->b['member']->id])->assertSessionHasErrors('assigned_to');

        // An unknown user and a user of another team get the same answer.
        $unknown = $this->actingAs($this->a['member'])->post(route('incidents.assign', $incident), ['assigned_to' => 999999])->assertSessionHasErrors('assigned_to');
        $unknownMessage = $this->errorFor($unknown, 'assigned_to');
        $foreign = $this->actingAs($this->a['member'])->post(route('incidents.assign', $incident), ['assigned_to' => $this->b['member']->id]);
        $this->assertSame($unknownMessage, $this->errorFor($foreign, 'assigned_to'));

        $this->actingAs($this->a['member'])->post(route('incidents.assign', $incident), ['assigned_to' => $this->a['admin']->id])->assertSessionHasNoErrors();
        $this->assertSame($this->a['admin']->id, $incident->fresh()->assigned_to);
    }

    public function test_resources_of_another_team_cannot_be_linked(): void
    {
        $admin = $this->a['admin'];
        // This test is about isolation, not plan limits (team A already has a status page).
        config(['serverscope.plans.free.limits.status_pages' => null]);

        // Status page component
        $this->actingAs($admin)->post(route('status-pages.store'), [
            'name' => 'Mijn status', 'slug' => 'mijn-status', 'accent_color' => '#38bdf8',
            'components' => [['subject' => 'server:'.$this->b['server']->id, 'display_name' => 'Gestolen']],
        ])->assertSessionHasErrors();
        $this->assertFalse(StatusPage::withoutGlobalScopes()->where('slug', 'mijn-status')->exists());

        // Dependency on, or of, a resource of another team
        foreach ([['domain:'.$this->a['domain']->id, 'server:'.$this->b['server']->id], ['domain:'.$this->b['domain']->id, 'server:'.$this->a['server']->id], ['page:'.$this->a['page']->id, 'service:'.$this->b['service']->id]] as [$dependent, $dependency]) {
            $this->actingAs($admin)->post(route('dependencies.store'), ['dependent' => $dependent, 'dependency' => $dependency])->assertSessionHasErrors();
        }

        $this->assertSame(2, ResourceDependency::withoutGlobalScopes()->count(), 'only the two fixture dependencies exist');

        // Manual incident about a server of another team
        $this->actingAs($admin)->post(route('incidents.store'), ['title' => 'x', 'severity' => 'warning', 'subject' => 'server:'.$this->b['server']->id])->assertNotFound();
    }

    public function test_domain_names_are_unique_per_team_and_invisible_across_teams(): void
    {
        $payload = ['scheme' => 'https', 'path' => '/', 'check_interval_minutes' => 5, 'timeout_seconds' => 10, 'failure_threshold' => 3, 'recovery_threshold' => 2,
            'http_enabled' => '1', 'ssl_enabled' => '1', 'dns_enabled' => '1', 'expiry_enabled' => '1', 'ssl_warning_days' => '30', 'expiry_warning_days' => '30'];

        // A domain of another team (active or soft-deleted) is no obstacle.
        \App\Models\Domain::forceCreate(['team_id' => $this->b['team']->id, 'name' => 'bravo-oud.example.com'])->delete();

        foreach (['bravo-shop.example.com', 'bravo-oud.example.com'] as $name) {
            $this->actingAs($this->a['admin'])->post(route('domains.store'), ['name' => $name, ...$payload])->assertSessionHasNoErrors();
            $this->assertTrue(\App\Models\Domain::query()->where('name', $name)->exists());
        }

        // Within the own team the specific messages remain.
        $own = $this->actingAs($this->a['admin'])->post(route('domains.store'), ['name' => 'alpha-shop.example.com', ...$payload]);
        $this->assertStringContainsString('gemonitord', $this->errorFor($own, 'name'));
    }

    public function test_private_status_page_of_another_team_is_not_visible(): void
    {
        $this->actingAs($this->a['admin'])->get(route('status.show', 'bravo-status'))->assertNotFound();
        $this->actingAs($this->a['admin'])->get(route('status.json', 'bravo-status'))->assertNotFound();
        $this->actingAs($this->b['member'])->get(route('status.show', 'bravo-status'))->assertOk()->assertSee('bravo component');
    }

    public function test_user_management_is_per_team(): void
    {
        $this->actingAs($this->a['admin'])->get(route('users.index'))->assertOk()->assertSee('alpha lid')->assertDontSee('bravo lid');

        // The last owner/admin rule counts per team: team A's admin may not
        // demote themselves even though team B has admins.
        $this->actingAs($this->a['admin'])->put(route('users.update', $this->a['admin']), ['is_admin' => '0'])->assertSessionHas('error');

        $this->actingAs($this->a['admin'])->put(route('users.update', $this->a['member']), ['is_admin' => '1'])->assertSessionHas('success');
        $this->assertTrue($this->a['member']->fresh()->isAdminOf($this->a['team']));
        $this->assertFalse($this->a['member']->fresh()->isAdminOf($this->b['team']));
    }

    /** The first validation message of the latest response (read it before the next request). */
    private function errorFor(\Illuminate\Testing\TestResponse $response, string $key): string
    {
        $errors = app('session.store')->get('errors');

        // An error bag, or its serialized form after a later request.
        return $errors instanceof \Illuminate\Support\ViewErrorBag
            ? (string) $errors->first($key)
            : (string) data_get($errors, "default.messages.{$key}.0");
    }
}
