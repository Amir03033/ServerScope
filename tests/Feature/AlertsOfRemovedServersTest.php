<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\Server;
use App\Services\AgentHeartbeatService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** A removed server is no longer evaluated, so its active alerts must end instead of staying active for ever. */
class AlertsOfRemovedServersTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->createTwoTeams();
    }

    private function offlineAlert(Server $server): Alert
    {
        return Tenant::run($server->team_id, function () use ($server) {
            $rule = AlertRule::forceCreate(['team_id' => $server->team_id, 'name' => 'Agent offline', 'metric' => AlertRule::METRIC_AGENT_OFFLINE, 'threshold' => 5, 'operator' => '>=', 'duration_minutes' => 0, 'is_enabled' => true, 'severity' => 'critical']);

            return Alert::query()->create(['server_id' => $server->id, 'alert_rule_id' => $rule->id, 'metric' => AlertRule::METRIC_AGENT_OFFLINE, 'observed_value' => 70, 'threshold' => 5, 'status' => 'active', 'triggered_at' => now()->subHour()]);
        });
    }

    public function test_removing_a_server_ends_its_alerts_and_their_incident_quietly(): void
    {
        $alert = $this->offlineAlert($this->a['server']);
        $incident = Incident::query()->withoutGlobalScopes()->findOrFail($alert->fresh()->incident_id);
        $this->assertTrue($incident->isActive());

        $this->actingAs($this->a['admin'])->delete(route('servers.destroy', $this->a['server']))->assertRedirect();

        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertFalse($incident->fresh()->isActive());
        $this->assertTrue($incident->fresh()->events()->where('message', 'like', '%verwijderd%')->exists());

        // Finished, so it can be cleared from the history now.
        $this->delete(route('alerts.destroy', $alert))->assertRedirect();
        $this->assertNull(Alert::query()->withoutGlobalScopes()->find($alert->id));
    }

    public function test_alerts_left_behind_by_servers_removed_earlier_end_on_the_next_check(): void
    {
        $stuck = $this->offlineAlert($this->a['server']);
        $other = $this->offlineAlert($this->b['server']);
        // Removed before this fix: no model events, the alert stayed active.
        DB::table('servers')->where('id', $this->a['server']->id)->update(['deleted_at' => now()]);
        // The other server is still silent, so its alert stays.
        DB::table('servers')->where('id', $this->b['server']->id)->update(['last_seen_at' => now()->subHours(2)]);

        app(AgentHeartbeatService::class)->check();

        $this->assertSame('resolved', $stuck->fresh()->status);
        $this->assertSame('active', $other->fresh()->status, 'alerts of servers that still exist are untouched');
    }
}
