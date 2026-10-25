<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create(['is_admin' => false]);
    }

    public function test_index_lists_filters_and_searches_servers(): void
    {
        $online = $this->server(['name' => 'Web Alpha', 'last_seen_at' => now(), 'environment' => 'production']);
        $offline = $this->server(['name' => 'Db Beta', 'last_seen_at' => now()->subMinutes(10), 'environment' => 'staging']);
        $pending = $this->server(['name' => 'New Gamma', 'last_seen_at' => null]);
        $maintenance = $this->server([
            'name' => 'Maint Delta',
            'last_seen_at' => now()->subHour(),
            'maintenance_starts_at' => now()->subMinutes(5),
            'maintenance_ends_at' => now()->addHour(),
        ]);

        $this->actingAs($this->viewer)->get(route('servers.index'))
            ->assertOk()
            ->assertSee(['Web Alpha', 'Db Beta', 'New Gamma', 'Maint Delta'])
            ->assertDontSee(route('servers.create'));

        $this->assertStatusFilter('online', $online);
        $this->assertStatusFilter('offline', $offline);
        $this->assertStatusFilter('pending', $pending);
        $this->assertStatusFilter('maintenance', $maintenance);

        $this->actingAs($this->viewer)->get(route('servers.index', ['q' => 'beta']))
            ->assertSee('Db Beta')->assertDontSee('Web Alpha');

        $this->actingAs($this->viewer)->get(route('servers.index', ['environment' => 'staging']))
            ->assertSee('Db Beta')->assertDontSee('Web Alpha');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->server(['name' => 'Alpha']);

        $this->actingAs($this->viewer)->get(route('servers.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('Alpha</a>', false);
    }

    public function test_non_admins_cannot_manage_servers(): void
    {
        $server = $this->server();

        $this->actingAs($this->viewer)->get(route('servers.create'))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('servers.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->get(route('servers.edit', $server))->assertForbidden();
        $this->actingAs($this->viewer)->put(route('servers.update', $server), $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('servers.destroy', $server))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('servers.maintenance.start', $server), ['duration_minutes' => 60])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('servers.credentials.rotate', $server))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('servers.index', ['status' => 'deleted']))->assertForbidden();

        $this->assertNotSoftDeleted($server);
        $this->assertSame(0, $server->agentCredentials()->count());
    }

    public function test_admin_registers_server_and_token_is_shown_only_once(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('servers.store'), $this->payload(['hostname' => 'NEW.Example.com.']));

        $server = Server::query()->sole();
        $response->assertRedirect(route('servers.show', $server));

        $this->assertSame('new.example.com', $server->hostname);
        $this->assertSame('staging', $server->environment);

        $token = session('agent_token');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $this->assertDatabaseHas('agent_credentials', ['server_id' => $server->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('agent_credentials', ['token_hash' => $token]);

        $this->get(route('servers.show', $server))->assertSee($token);
        $this->get(route('servers.show', $server))->assertDontSee($token);

        $log = AuditLog::query()->where('action', 'server.created')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringNotContainsString($token, json_encode($log->toArray()));
    }

    public function test_server_validation(): void
    {
        $this->actingAs($this->admin)
            ->post(route('servers.store'), $this->payload([
                'hostname' => 'not a host!',
                'environment' => 'mars',
                'ip_address' => '999.1.1.1',
            ]))
            ->assertSessionHasErrors(['hostname', 'environment', 'ip_address']);

        $this->assertSame(0, Server::query()->count());
    }

    public function test_admin_updates_server_and_changes_are_audited(): void
    {
        $server = $this->server(['name' => 'Old name']);

        $this->actingAs($this->admin)
            ->put(route('servers.update', $server), $this->payload(['name' => 'New name', 'notes' => 'Hetzner']))
            ->assertRedirect(route('servers.show', $server));

        $server->refresh();
        $this->assertSame('New name', $server->name);
        $this->assertSame('Hetzner', $server->notes);

        $log = AuditLog::query()->where('action', 'server.updated')->sole();
        $this->assertSame(['old' => 'Old name', 'new' => 'New name'], $log->properties['changes']['name']);
    }

    public function test_maintenance_window_is_entered_in_display_timezone_and_stored_in_utc(): void
    {
        config(['serverscope.display_timezone' => 'Europe/Amsterdam']);
        $server = $this->server();

        $this->actingAs($this->admin)
            ->put(route('servers.update', $server), $this->payload([
                'maintenance_starts_at' => '2030-07-01T10:00',
                'maintenance_ends_at' => '2030-07-01T12:00',
                'maintenance_reason' => 'Kernel-update',
            ]))
            ->assertSessionHasNoErrors();

        $server->refresh();
        $this->assertSame('2030-07-01 08:00:00', $server->maintenance_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-07-01 10:00:00', $server->maintenance_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($server->hasScheduledMaintenance());

        $this->actingAs($this->admin)
            ->put(route('servers.update', $server), $this->payload([
                'maintenance_starts_at' => '2030-07-01T12:00',
                'maintenance_ends_at' => '2030-07-01T10:00',
            ]))
            ->assertSessionHasErrors('maintenance_ends_at');
    }

    public function test_maintenance_suppresses_offline_and_metric_alerts(): void
    {
        AlertRule::query()->forceDelete();
        AlertRule::create(['name' => 'Offline', 'metric' => 'agent_offline', 'threshold' => 5, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);
        AlertRule::create(['name' => 'CPU', 'metric' => 'cpu_percent', 'threshold' => 80, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);

        [$server, $token] = $this->agentServer(['last_seen_at' => now()->subMinutes(30)]);

        $this->actingAs($this->admin)
            ->post(route('servers.maintenance.start', $server), ['duration_minutes' => 60, 'reason' => 'Upgrade'])
            ->assertSessionHasNoErrors();

        $this->assertSame('maintenance', $server->fresh()->displayStatus());

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(0, Alert::query()->count());

        $start = now()->subMinute()->startOfMinute();
        foreach ([95, 96, 97] as $i => $cpu) {
            $this->withToken($token)->postJson('/api/agent/metrics', [
                'collected_at' => $start->copy()->addSeconds($i * 20)->toIso8601String(),
                'cpu_percent' => $cpu,
            ])->assertCreated();
        }
        $this->assertSame(0, Alert::query()->count());

        // After the window the still-high CPU triggers on the next sample.
        $this->travel(61)->minutes();
        foreach ([95, 96, 97, 98] as $i => $cpu) {
            $this->withToken($token)->postJson('/api/agent/metrics', [
                'collected_at' => now()->subSeconds(60 - $i * 20)->toIso8601String(),
                'cpu_percent' => $cpu,
            ])->assertCreated();
        }
        $this->assertSame(1, Alert::query()->where('metric', 'cpu_percent')->count());
    }

    public function test_maintenance_end_restores_offline_alerting(): void
    {
        AlertRule::query()->forceDelete();
        AlertRule::create(['name' => 'Offline', 'metric' => 'agent_offline', 'threshold' => 5, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);

        $server = $this->server([
            'last_seen_at' => now()->subMinutes(30),
            'maintenance_starts_at' => now()->subHour(),
        ]);

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(0, Alert::query()->count());

        $this->actingAs($this->admin)->delete(route('servers.maintenance.end', $server))->assertRedirect();
        $this->assertNull($server->fresh()->maintenance_starts_at);

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(1, Alert::query()->count());
    }

    public function test_soft_delete_revokes_credentials_and_keeps_history(): void
    {
        [$server, $token] = $this->agentServer();
        $sample = MetricSample::create(['server_id' => $server->id, 'cpu_percent' => 5, 'collected_at' => now()->subMinutes(3)]);

        $this->actingAs($this->admin)->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'));

        $this->assertSoftDeleted($server);
        $this->assertDatabaseHas('metric_samples', ['id' => $sample->id]);
        $this->assertNotNull($server->agentCredentials()->sole()->revoked_at);

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => 1,
        ])->assertUnauthorized();

        $listed = $this->actingAs($this->viewer)->get(route('servers.index'))->viewData('servers');
        $this->assertFalse($listed->contains('id', $server->id));
        $this->actingAs($this->admin)->get(route('servers.index', ['status' => 'deleted']))->assertSee($server->name);
        $this->actingAs($this->admin)->get(route('servers.show', $server))->assertOk()->assertSee('Deze server is verwijderd');
    }

    public function test_deleted_server_agent_is_rejected_even_with_unrevoked_credential(): void
    {
        [$server, $token] = $this->agentServer();
        $server->delete();

        $this->withToken($token)->getJson('/agent/ping')->assertUnauthorized();
    }

    public function test_restore_and_permanent_delete(): void
    {
        [$server] = $this->agentServer();
        $server->delete();

        $this->actingAs($this->admin)->post(route('servers.restore', $server))->assertRedirect(route('servers.show', $server));
        $this->assertNotSoftDeleted($server);

        $server->delete();

        $this->actingAs($this->admin)
            ->delete(route('servers.force-destroy', $server), ['confirm_name' => 'wrong'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);

        $this->actingAs($this->admin)
            ->delete(route('servers.force-destroy', $server), ['confirm_name' => $server->name])
            ->assertRedirect();
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('agent_credentials', ['server_id' => $server->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.force_deleted']);
    }

    public function test_active_server_cannot_be_permanently_deleted(): void
    {
        $server = $this->server();

        $this->actingAs($this->admin)
            ->delete(route('servers.force-destroy', $server), ['confirm_name' => $server->name])
            ->assertNotFound();
    }

    public function test_rotation_invalidates_previous_tokens_and_issues_a_new_one(): void
    {
        [$server, $oldToken] = $this->agentServer();
        [, $secondToken] = $this->issueToken($server, 'Second');

        $this->actingAs($this->admin)
            ->post(route('servers.credentials.rotate', $server), ['name' => 'Rotated'])
            ->assertRedirect(route('servers.show', $server));

        $newToken = session('agent_token');
        $this->assertNotSame($oldToken, $newToken);

        $this->withToken($oldToken)->getJson('/agent/ping')->assertUnauthorized();
        $this->withToken($secondToken)->getJson('/agent/ping')->assertUnauthorized();
        $this->withToken($newToken)->getJson('/agent/ping')->assertOk();

        $this->assertSame(1, $server->agentCredentials()->whereNull('revoked_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent_credential.rotated']);
    }

    public function test_single_credential_can_be_revoked_and_extra_issued(): void
    {
        [$server, $token] = $this->agentServer();
        $credential = $server->agentCredentials()->sole();

        $this->actingAs($this->admin)
            ->post(route('servers.credentials.store', $server), ['name' => 'Backup agent'])
            ->assertRedirect();
        $extra = session('agent_token');

        $this->actingAs($this->admin)
            ->delete(route('servers.credentials.destroy', [$server, $credential]))
            ->assertRedirect();

        $this->withToken($token)->getJson('/agent/ping')->assertUnauthorized();
        $this->withToken($extra)->getJson('/agent/ping')->assertOk();
    }

    public function test_credential_route_is_scoped_to_its_server(): void
    {
        [$serverA] = $this->agentServer();
        [$serverB] = $this->agentServer();
        $credentialB = $serverB->agentCredentials()->sole();

        $this->actingAs($this->admin)
            ->delete(route('servers.credentials.destroy', [$serverA, $credentialB]))
            ->assertNotFound();

        $this->assertNull($credentialB->fresh()->revoked_at);
    }

    public function test_credentials_are_only_listed_for_admins_and_never_expose_hashes(): void
    {
        [$server] = $this->agentServer();
        $hash = $server->agentCredentials()->sole()->token_hash;

        $this->actingAs($this->viewer)->get(route('servers.show', $server))
            ->assertOk()->assertDontSee('Agentcredentials')->assertDontSee($hash);

        $this->actingAs($this->admin)->get(route('servers.show', $server))
            ->assertOk()->assertSee('Agentcredentials')->assertDontSee($hash);
    }

    private function assertStatusFilter(string $status, Server $expected): void
    {
        $servers = $this->actingAs($this->viewer)
            ->get(route('servers.index', ['status' => $status]))
            ->assertOk()
            ->viewData('servers');

        $this->assertSame([$expected->id], $servers->pluck('id')->all(), "Filter {$status}");
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Edge VPS',
            'hostname' => 'edge.example.com',
            'ip_address' => '203.0.113.5',
            'operating_system' => 'Debian 13',
            'environment' => 'staging',
            'credential_name' => 'Primary agent',
            ...$overrides,
        ];
    }

    private function server(array $attributes = []): Server
    {
        return Server::create([
            'name' => 'VPS '.uniqid(),
            'hostname' => uniqid().'.example.test',
            ...$attributes,
        ]);
    }

    private function agentServer(array $attributes = []): array
    {
        $server = $this->server(['last_seen_at' => now(), ...$attributes]);
        [, $token] = $this->issueToken($server, 'Primary');

        return [$server, $token];
    }

    private function issueToken(Server $server, string $name): array
    {
        $token = bin2hex(random_bytes(32));
        $credential = $server->agentCredentials()->create(['name' => $name, 'token_hash' => hash('sha256', $token)]);

        return [$credential, $token];
    }
}
