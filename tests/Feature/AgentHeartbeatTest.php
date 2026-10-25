<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentHeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start each test from a known rule set instead of the
        // default rule inserted by the migration.
        AlertRule::query()->forceDelete();
    }

    public function test_default_agent_offline_rule_is_installed_by_migration(): void
    {
        $this->refreshApplication();
        $this->artisan('migrate:fresh');

        $this->assertDatabaseHas('alert_rules', [
            'metric' => 'agent_offline',
            'threshold' => 5,
            'is_enabled' => true,
        ]);
    }

    public function test_silent_server_is_marked_offline(): void
    {
        $silent = $this->server(now()->subMinutes(3));
        $fresh = $this->server(now()->subSeconds(30));
        $pending = Server::create([
            'name' => 'Pending',
            'hostname' => 'pending.example.test',
        ]);

        $result = app(AgentHeartbeatService::class)->check();

        $this->assertSame(1, $result['marked_offline']);
        $this->assertSame('offline', $silent->fresh()->status);
        $this->assertSame('online', $fresh->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_alert_triggers_only_after_threshold(): void
    {
        $rule = $this->rule(threshold: 5);
        $server = $this->server(now()->subMinutes(4));

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(0, Alert::query()->count());

        $this->travel(2)->minutes();
        $result = app(AgentHeartbeatService::class)->check();

        $this->assertSame(1, $result['triggered']);

        $alert = Alert::query()->sole();
        $this->assertSame($server->id, $alert->server_id);
        $this->assertSame($rule->id, $alert->alert_rule_id);
        $this->assertSame('agent_offline', $alert->metric);
        $this->assertSame('active', $alert->status);
        $this->assertTrue($alert->triggered_at->eq($server->last_seen_at));
    }

    public function test_repeated_checks_do_not_duplicate_alerts(): void
    {
        $this->rule(threshold: 5);
        $this->server(now()->subMinutes(10));

        $service = app(AgentHeartbeatService::class);
        $service->check();
        $this->travel(1)->minutes();
        $second = $service->check();
        $this->travel(1)->minutes();
        $service->check();

        $this->assertSame(0, $second['triggered']);
        $this->assertSame(1, Alert::query()->count());
        $this->assertEqualsWithDelta(12, Alert::query()->sole()->observed_value, 0.1);
    }

    public function test_alert_resolves_when_agent_reports_again(): void
    {
        $this->rule(threshold: 5);
        [$server, $token] = $this->agent(now()->subMinutes(10));

        app(AgentHeartbeatService::class)->check();
        $this->assertSame('active', Alert::query()->sole()->status);

        $this->withToken($token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => 10,
        ])->assertCreated();

        $alert = Alert::query()->sole();
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('online', $server->fresh()->status);
    }

    public function test_alert_resolves_on_scheduled_check_after_fresh_heartbeat(): void
    {
        $this->rule(threshold: 5);
        $server = $this->server(now()->subMinutes(10));

        $service = app(AgentHeartbeatService::class);
        $service->check();

        $server->forceFill(['last_seen_at' => now()])->save();
        $result = $service->check();

        $this->assertSame(1, $result['resolved']);
        $this->assertSame('resolved', Alert::query()->sole()->status);
    }

    public function test_disabled_rules_and_never_seen_servers_are_ignored(): void
    {
        $this->rule(threshold: 5, enabled: false);
        $this->server(now()->subHour());

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(0, Alert::query()->count());

        $this->rule(threshold: 5);
        Server::create([
            'name' => 'Never seen',
            'hostname' => 'never.example.test',
        ]);

        app(AgentHeartbeatService::class)->check();
        $this->assertSame(1, Alert::query()->count());
    }

    public function test_check_command_runs_and_is_scheduled(): void
    {
        $this->rule(threshold: 5);
        $this->server(now()->subMinutes(10));

        $this->artisan('serverscope:check-agents')->assertSuccessful();
        $this->assertSame(1, Alert::query()->count());

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command ?? '', 'serverscope:check-agents'));

        $this->assertTrue($scheduled);
    }

    public function test_admin_can_create_agent_offline_rule_with_forced_operator(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('alert-rules.store'), [
                'name' => 'Agent stil',
                'metric' => 'agent_offline',
                'threshold' => 10,
                'operator' => '<',
                'duration_minutes' => 30,
                'is_enabled' => true,
            ])
            ->assertRedirect(route('alert-rules.index'));

        $this->assertDatabaseHas('alert_rules', [
            'name' => 'Agent stil',
            'metric' => 'agent_offline',
            'operator' => '>=',
            'duration_minutes' => 1,
        ]);
    }

    public function test_agent_offline_threshold_must_be_whole_minutes_of_at_least_two(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([1, 2.5, 5000] as $threshold) {
            $this->actingAs($admin)
                ->post(route('alert-rules.store'), [
                    'name' => 'Ongeldig',
                    'metric' => 'agent_offline',
                    'threshold' => $threshold,
                    'operator' => '>=',
                    'duration_minutes' => 1,
                    'is_enabled' => true,
                ])
                ->assertSessionHasErrors('threshold');
        }

        $this->assertDatabaseMissing('alert_rules', ['name' => 'Ongeldig']);
    }

    public function test_alert_pages_render_agent_offline_alerts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->rule(threshold: 5);
        $this->server(now()->subMinutes(10));
        app(AgentHeartbeatService::class)->check();

        $this->actingAs($admin)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Agent offline')
            ->assertSee('10,0 min');

        $this->actingAs($admin)
            ->get(route('alert-rules.index'))
            ->assertOk()
            ->assertSee('5,0 min');
    }

    private function rule(float $threshold, bool $enabled = true): AlertRule
    {
        return AlertRule::create([
            'name' => 'Agent offline',
            'metric' => 'agent_offline',
            'threshold' => $threshold,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => $enabled,
        ]);
    }

    private function server($lastSeenAt): Server
    {
        return Server::create([
            'name' => 'VPS '.uniqid(),
            'hostname' => uniqid().'.example.test',
            'status' => 'online',
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    private function agent($lastSeenAt): array
    {
        $server = $this->server($lastSeenAt);
        $token = bin2hex(random_bytes(32));

        $server->agentCredentials()->create([
            'name' => 'Test agent',
            'token_hash' => hash('sha256', $token),
        ]);

        return [$server, $token];
    }
}
