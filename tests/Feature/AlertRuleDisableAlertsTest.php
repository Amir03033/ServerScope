<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertRuleDisableAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_rule_marks_its_active_alerts_as_disabled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $server = $this->createServer();
        $rule = $this->createRule();

        $activeAlert = $this->createAlert($server, $rule, 'active');
        $resolvedAlert = $this->createAlert($server, $rule, 'resolved');

        $this->actingAs($admin)
            ->put(route('alert-rules.update', $rule), $this->rulePayload([
                'is_enabled' => false,
            ]))
            ->assertRedirect(route('alert-rules.index'));

        $this->assertDatabaseHas('alert_rules', [
            'id' => $rule->id,
            'is_enabled' => false,
        ]);

        $this->assertDatabaseHas('alerts', [
            'id' => $activeAlert->id,
            'status' => 'disabled',
        ]);

        $this->assertNotNull($activeAlert->fresh()->resolved_at);

        // Een eerder opgelost incident blijft opgelost.
        $this->assertDatabaseHas('alerts', [
            'id' => $resolvedAlert->id,
            'status' => 'resolved',
        ]);
    }

    public function test_disabling_rule_only_affects_alerts_belonging_to_that_rule(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $server = $this->createServer();

        $ruleToDisable = $this->createRule();
        $otherRule = $this->createRule([
            'name' => 'Other rule',
            'metric' => 'memory_percent',
        ]);

        $alertToDisable = $this->createAlert($server, $ruleToDisable, 'active');
        $otherAlert = $this->createAlert($server, $otherRule, 'active');

        $this->actingAs($admin)
            ->put(
                route('alert-rules.update', $ruleToDisable),
                $this->rulePayload(['is_enabled' => false]),
            )
            ->assertRedirect(route('alert-rules.index'));

        $this->assertDatabaseHas('alerts', [
            'id' => $alertToDisable->id,
            'status' => 'disabled',
        ]);

        $this->assertDatabaseHas('alerts', [
            'id' => $otherAlert->id,
            'status' => 'active',
        ]);
    }

    public function test_reenabling_rule_does_not_reactivate_old_disabled_alerts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $server = $this->createServer();
        $rule = $this->createRule(['is_enabled' => false]);

        $oldAlert = $this->createAlert($server, $rule, 'disabled');

        $this->actingAs($admin)
            ->put(
                route('alert-rules.update', $rule),
                $this->rulePayload(['is_enabled' => true]),
            )
            ->assertRedirect(route('alert-rules.index'));

        $this->assertDatabaseHas('alert_rules', [
            'id' => $rule->id,
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('alerts', [
            'id' => $oldAlert->id,
            'status' => 'disabled',
        ]);
    }

    public function test_disabled_alerts_have_a_separate_filter(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $server = $this->createServer();
        $rule = $this->createRule();

        $disabledAlert = $this->createAlert($server, $rule, 'disabled');

        $this->actingAs($user)
            ->get(route('alerts.index', ['status' => 'disabled']))
            ->assertOk()
            ->assertSee('Uitgeschakeld')
            ->assertSee($rule->name);
    }

    public function test_alerts_page_counts_disabled_alerts_separately(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $server = $this->createServer();
        $rule = $this->createRule();

        $this->createAlert($server, $rule, 'active');
        $this->createAlert($server, $rule, 'resolved');
        $this->createAlert($server, $rule, 'disabled');

        $this->actingAs($user)
            ->get(route('alerts.index', ['status' => 'all']))
            ->assertOk()
            ->assertViewHas('activeCount', 1)
            ->assertViewHas('resolvedCount', 1)
            ->assertViewHas('disabledCount', 1)
            ->assertViewHas('totalCount', 3);
    }

    private function createServer(): Server
    {
        return Server::create([
            'name' => 'Alert Test Server',
            'hostname' => 'alerts.example.test',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    private function createRule(array $overrides = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'name' => 'High CPU Usage',
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ], $overrides));
    }

    private function createAlert(
        Server $server,
        AlertRule $rule,
        string $status,
    ): Alert {
        return Alert::create([
            'server_id' => $server->id,
            'alert_rule_id' => $rule->id,
            'metric' => $rule->metric,
            'observed_value' => 95,
            'threshold' => $rule->threshold,
            'status' => $status,
            'triggered_at' => now()->subMinutes(5),
            'resolved_at' => $status === 'active' ? null : now(),
        ]);
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'High CPU Usage',
            'metric' => 'cpu_percent',
            'threshold' => 85,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ], $overrides);
    }
}
