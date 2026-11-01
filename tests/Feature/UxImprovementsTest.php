<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Favorite;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\IncidentMemory;
use App\Services\Incidents\Signal;
use App\Support\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class UxImprovementsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------- error pages ----------

    public function test_unknown_routes_and_other_teams_resources_get_the_same_branded_404(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/does-not-exist')->assertNotFound()->assertSee(trans('errors.404.title'))->assertSee('ServerScope')->assertDontSee('Symfony');
        $this->assertSame(0, $queries, 'the 404 page needs no database');

        $this->actingAs($this->a['admin'])->get(route('servers.show', $this->b['server']))->assertNotFound()
            ->assertSee(trans('errors.404.title', [], 'nl'))->assertDontSee($this->b['server']->name);
    }

    public function test_403_419_429_and_500_pages_keep_their_status_and_hide_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test/419', fn () => abort(419));
        Route::middleware('web')->get('/__test/429', fn () => abort(429));
        Route::middleware('web')->get('/__test/500', fn () => throw new RuntimeException('SQLSTATE secret /var/www/serverscope/.env DB_PASSWORD=hunter2'));

        $this->actingAs($this->a['member'])->get(route('audit-logs.index'))->assertForbidden()->assertSee(trans('errors.403.title', [], 'nl'));
        $this->get('/__test/419')->assertStatus(419)->assertSee(trans('errors.419.title', [], 'nl'));
        $this->get('/__test/429')->assertStatus(429)->assertSee(trans('errors.429.title', [], 'nl'));
        $this->get('/__test/500')->assertStatus(500)->assertSee(trans('errors.500.title', [], 'nl'))
            ->assertDontSee('SQLSTATE')->assertDontSee('hunter2')->assertDontSee('/var/www');
    }

    public function test_maintenance_mode_shows_the_branded_503_without_the_database(): void
    {
        $this->artisan('down', ['--retry' => 300])->assertSuccessful();

        try {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            $response = $this->get('/login')->assertStatus(503)->assertHeader('Retry-After', '300');
            $response->assertSee(trans('errors.503.title'))->assertSee(trans_choice('errors.503.retry', 5, ['minutes' => 5]));
            $this->assertStringNotContainsString('/build/assets', $response->getContent(), 'no Vite assets needed');
            $this->assertSame(0, $queries);
        } finally {
            $this->artisan('up');
        }

        // Without --retry no return time is claimed.
        $this->artisan('down')->assertSuccessful();

        try {
            $this->get('/login')->assertStatus(503)->assertSee(trans('errors.503.no_estimate'));
        } finally {
            $this->artisan('up');
        }
    }

    // ---------- search ----------

    public function test_search_finds_own_deployments_and_never_another_teams(): void
    {
        Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->a['team']->id, 'application' => 'shop-api', 'version' => '2.0', 'deployed_at' => now()]);
        Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->b['team']->id, 'application' => 'shop-bravo', 'deployed_at' => now()]);

        $results = $this->actingAs($this->a['member'])->getJson(route('search', ['q' => 'shop']))->assertOk()->json('results');
        $this->assertContains('shop-api 2.0', array_column($results, 'title'));
        $this->assertStringNotContainsString('bravo', json_encode($results));

        $this->actingAs($this->a['member'])->getJson(route('search', ['q' => 's']))->assertOk()->assertJsonPath('results', []);
        $this->actingAs($this->a['member'])->getJson(route('search', ['q' => "%' OR 1=1 --"]))->assertOk()->assertJsonPath('results', []);
        $this->actingAs($this->a['member'])->getJson(route('search', ['q' => str_repeat('x', 101)]))->assertUnprocessable();
        $this->actingAs($this->a['member'])->get(route('search', ['q' => 'shop']))->assertOk()->assertSee('shop-api');
    }

    // ---------- favourites ----------

    public function test_favourites_are_personal_unique_and_team_bound(): void
    {
        $server = $this->a['server'];
        $toggle = fn ($user, $type, $id) => $this->actingAs($user)->postJson(route('favorites.toggle'), ['type' => $type, 'id' => $id]);

        $toggle($this->a['member'], 'server', $server->id)->assertOk()->assertJsonPath('favorite', true);
        // Duplicate inserts cannot happen: the unique key and insertOrIgnore.
        Favorite::query()->withoutGlobalScopes()->insertOrIgnore(['user_id' => $this->a['member']->id, 'team_id' => $this->a['team']->id, 'favoritable_type' => 'server', 'favoritable_id' => $server->id, 'created_at' => now()]);
        $this->assertSame(1, Favorite::query()->withoutGlobalScopes()->count());

        // Only for this user.
        $this->actingAs($this->a['member'])->get(route('servers.index', ['favorites' => 1]))->assertOk()->assertSee($server->name);
        $this->actingAs($this->a['admin'])->get(route('servers.index', ['favorites' => 1]))->assertOk()->assertDontSee($server->name.'</a>', false)->assertSee(trans('favorites.empty', [], 'nl'));

        // Another team's resource does not exist for this user.
        $toggle($this->a['member'], 'server', $this->b['server']->id)->assertNotFound();
        $toggle($this->a['member'], 'domain', $this->b['domain']->id)->assertNotFound();
        $toggle($this->a['member'], 'incident', 1)->assertUnprocessable();

        $toggle($this->a['member'], 'server', $server->id)->assertOk()->assertJsonPath('favorite', false);
        $this->assertSame(0, Favorite::query()->withoutGlobalScopes()->count());

        // Without JavaScript: a normal redirect.
        $this->actingAs($this->a['member'])->from(route('domains.index'))->post(route('favorites.toggle'), ['type' => 'domain', 'id' => $this->a['domain']->id])->assertRedirect(route('domains.index'));
        $this->actingAs($this->a['member'])->get(route('domains.index', ['favorites' => 1]))->assertOk()->assertSee($this->a['domain']->name);
    }

    // ---------- duplicate configuration ----------

    public function test_a_domain_configuration_is_duplicated_without_identity(): void
    {
        $source = $this->a['domain'];
        $source->forceFill(['check_interval_minutes' => 7, 'timeout_seconds' => 15, 'expected_content' => 'Welkom', 'notes' => 'private note', 'path' => '/shop', 'dns_enabled' => false])->save();

        $response = $this->actingAs($this->a['admin'])->get(route('domains.create', ['from' => $source->id]))->assertOk()
            ->assertSee(trans('domains.duplicate_notice', ['name' => $source->displayName()], 'nl'));
        $copy = $response->viewData('domain');
        $this->assertSame([7, 15, 'Welkom', false], [$copy->check_interval_minutes, $copy->timeout_seconds, $copy->expected_content, $copy->dns_enabled]);
        $this->assertNull($copy->name);
        $this->assertNull($copy->notes);
        $this->assertSame('/', $copy->path);
        $this->assertFalse($copy->exists);

        $this->actingAs($this->a['admin'])->get(route('domains.create', ['from' => $this->b['domain']->id]))->assertNotFound();
        $this->actingAs($this->a['member'])->get(route('domains.create', ['from' => $source->id]))->assertForbidden();
        $this->assertSame(1, Domain::query()->withoutGlobalScopes()->where('team_id', $this->a['team']->id)->count(), 'nothing is created before saving');
    }

    // ---------- health and freshness ----------

    public function test_health_follows_the_monitoring_rules_and_missing_data_is_unknown(): void
    {
        $server = $this->a['server'];
        $this->assertSame('healthy', Health::server($server));
        $this->assertSame('critical', Health::server($server, 'critical'));
        $this->assertSame('warning', Health::server($server, 'high'));

        // Silent agent, website on it still up: unknown, not offline.
        $server->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();
        $this->a['domain']->forceFill(['http_enabled' => true, 'http_status' => 'up', 'http_consecutive_failures' => 0, 'http_last_checked_at' => now()->subMinute()])->save();
        $this->assertSame('unknown', Health::server($server->fresh()));

        // Silent agent and the website on it fails too: offline.
        $this->a['domain']->forceFill(['http_status' => 'down', 'http_consecutive_failures' => 3])->save();
        $this->assertSame('offline', Health::server($server->fresh()));

        $server->forceFill(['maintenance_starts_at' => now()->subMinute(), 'maintenance_ends_at' => now()->addHour()])->save();
        $this->assertSame('maintenance', Health::server($server->fresh()));
        $this->assertSame('unknown', Health::server(Server::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'nieuw', 'hostname' => 'nieuw.test'])));

        $domain = $this->a['domain']->fresh();
        $this->assertSame('critical', Health::domain($domain));
        $domain->forceFill(['is_active' => false])->save();
        $this->assertSame('paused', Health::domain($domain));
        $this->assertSame('unknown', Health::domain(Domain::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'leeg.example.com'])));

        // The list shows the same state with text and a description.
        $this->actingAs($this->a['member'])->get(route('domains.index'))->assertOk()->assertSee('data-health="paused"', false)->assertSee(trans('health.descriptions.paused', [], 'nl'));
    }

    public function test_stale_metrics_are_shown_as_stale_not_as_downtime(): void
    {
        $server = $this->a['server'];
        MetricSample::query()->where('server_id', $server->id)->delete();
        MetricSample::create(['server_id' => $server->id, 'cpu_percent' => 5, 'collected_at' => now()->subMinutes(20)]);
        $server->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();

        $this->actingAs($this->a['member'])->get(route('servers.show', $server))->assertOk()
            ->assertSee('data-stale', false)
            ->assertSee('data-health="unknown"', false)
            ->assertDontSee('data-health="offline"', false);

        // During maintenance stale metrics are expected.
        $server->forceFill(['maintenance_starts_at' => now()->subHour(), 'maintenance_ends_at' => now()->addHour()])->save();
        $this->actingAs($this->a['member'])->get(route('servers.show', $server))->assertOk()->assertDontSee('data-stale', false);

        // Domain: next check from its own interval; stale when overdue.
        $this->a['domain']->forceFill(['is_active' => true, 'http_enabled' => true, 'check_interval_minutes' => 5, 'http_last_checked_at' => now()->subMinutes(30)])->save();
        $this->actingAs($this->a['member'])->get(route('domains.show', $this->a['domain']))->assertOk()->assertSee('data-stale', false);
        $this->a['domain']->forceFill(['http_last_checked_at' => now()->subMinutes(2)])->save();
        $this->actingAs($this->a['member'])->get(route('domains.show', $this->a['domain']))->assertOk()->assertDontSee('data-stale', false);
    }

    // ---------- maintenance ----------

    public function test_maintenance_custom_end_time_is_validated_and_converted_from_the_display_timezone(): void
    {
        $server = $this->a['server'];
        $start = fn (array $data) => $this->actingAs($this->a['admin'])->post(route('servers.maintenance.start', $server), $data);

        // 15:00 Amsterdam (UTC+1 in November) = 14:00 UTC.
        $start(['duration_minutes' => 'custom', 'ends_at' => '2026-11-10T15:00'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-10 14:00:00', $server->fresh()->maintenance_ends_at->utc()->format('Y-m-d H:i:s'));

        $start(['duration_minutes' => 'custom', 'ends_at' => '2026-11-10T12:00'])->assertSessionHasErrors('ends_at'); // in the past
        $start(['duration_minutes' => 'custom', 'ends_at' => '2026-11-30T12:00'])->assertSessionHasErrors('ends_at'); // > 7 days
        $start(['duration_minutes' => 'custom'])->assertSessionHasErrors('ends_at');
        $start(['duration_minutes' => 17])->assertSessionHasErrors('duration_minutes');

        $start(['duration_minutes' => 240])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-10 16:00:00', $server->fresh()->maintenance_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($this->a['member'])->post(route('servers.maintenance.start', $server), ['duration_minutes' => 60])->assertForbidden();
    }

    // ---------- audit log ----------

    public function test_audit_log_filters_combine_and_stay_within_the_team(): void
    {
        AuditLog::query()->withoutGlobalScopes()->delete();
        $make = fn ($team, $type, $id, $at, $text) => AuditLog::forceCreate(['team_id' => $team, 'user_id' => $this->a['admin']->id, 'action' => 'test.done', 'auditable_type' => $type, 'auditable_id' => $id, 'description' => $text, 'created_at' => $at]);
        $make($this->a['team']->id, 'server', $this->a['server']->id, now()->subDays(3), 'alpha old server');
        $make($this->a['team']->id, 'server', $this->a['server']->id, now()->subHour(), 'alpha new server');
        $make($this->a['team']->id, 'domain', $this->a['domain']->id, now()->subHour(), 'alpha new domain');
        $make($this->b['team']->id, 'server', $this->a['server']->id, now()->subHour(), 'bravo entry');

        $get = fn (array $filters) => $this->actingAs($this->a['admin'])->get(route('audit-logs.index', $filters))->assertOk();

        $get(['type' => 'server'])->assertSee('alpha old server')->assertSee('alpha new server')->assertDontSee('alpha new domain')->assertDontSee('bravo entry');
        $get(['type' => 'server', 'from' => '2026-11-09'])->assertSee('alpha new server')->assertDontSee('alpha old server');
        $get(['resource' => $this->a['domain']->id, 'type' => 'domain', 'to' => '2026-11-10'])->assertSee('alpha new domain')->assertDontSee('alpha new server');
        $get(['q' => 'nothing-matches'])->assertSee(trans('admin.audit.none_found', [], 'nl'))->assertSee(trans('admin.audit.clear', [], 'nl'));
        $this->actingAs($this->a['admin'])->get(route('audit-logs.index', ['from' => 'yesterday']))->assertSessionHasErrors('from');
    }

    // ---------- quick actions and workflow ----------

    public function test_row_menus_only_offer_actions_the_user_may_use(): void
    {
        $this->actingAs($this->a['admin'])->get(route('servers.index'))->assertOk()
            ->assertSee(route('servers.edit', $this->a['server']))->assertSee(trans('servers.actions.metrics', [], 'nl'));
        $this->actingAs($this->a['member'])->get(route('servers.index'))->assertOk()
            ->assertDontSee(route('servers.edit', $this->a['server']))->assertSee(route('incidents.index', ['server' => $this->a['server']->id, 'state' => 'all']));

        $this->actingAs($this->a['admin'])->get(route('domains.index'))->assertOk()
            ->assertSee(route('domains.pause', $this->a['domain']))->assertSee(route('domains.create', ['from' => $this->a['domain']->id]), false);
        $this->actingAs($this->a['member'])->get(route('domains.index'))->assertOk()->assertDontSee(route('domains.pause', $this->a['domain']));
        // The action itself stays protected.
        $this->actingAs($this->a['member'])->post(route('domains.pause', $this->a['domain']))->assertForbidden();

        $this->actingAs($this->a['member'])->get(route('incidents.index'))->assertOk()
            ->assertSee(route('incidents.time-machine', $this->a['incident']))->assertDontSee(route('incidents.time-machine', $this->b['incident']));
    }

    public function test_the_incident_workflow_reflects_real_state(): void
    {
        $incident = app(IncidentManager::class)->trigger(new Signal('http:wf', 'http', 'critical', 'Shop down', null, $this->a['domain'], teamId: $this->a['team']->id));
        $page = fn () => $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk();

        $page()->assertSee('data-step="investigate" data-state="current"', false)->assertSee('data-step="cause" data-state="todo"', false);

        app(IncidentMemory::class)->save($incident, ['cause_category' => 'network', 'cause_status' => 'hypothesis', 'root_cause' => 'Upstream packet loss', 'outcome' => 'unresolved'], $this->a['admin'], null);
        $page()->assertSee('data-step="cause" data-state="done"', false)->assertSee(trans('incident_memory.cause_status.hypothesis', [], 'nl'));

        // Resolved but monitoring does not show the site healthy: recovery not verified.
        app(IncidentManager::class)->transition($incident->fresh(), 'resolved', $this->a['admin'], 'Fixed upstream');
        $this->a['domain']->forceFill(['http_status' => 'down', 'http_consecutive_failures' => 2, 'http_last_checked_at' => now()])->save();
        $page()->assertSee('data-step="resolve" data-state="done"', false)->assertSee('data-step="verify" data-state="current"', false);

        $this->a['domain']->forceFill(['http_status' => 'up', 'http_consecutive_failures' => 0, 'ssl_enabled' => false, 'dns_enabled' => false, 'expiry_enabled' => false])->save();
        $page()->assertSee('data-step="verify" data-state="done"', false);
        $this->assertSame('resolved', $incident->fresh()->status, 'nothing is resolved or closed automatically');
    }
}
