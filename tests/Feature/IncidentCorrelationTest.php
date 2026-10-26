<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MetricSample;
use App\Models\NotificationLog;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\User;
use App\Notifications\IncidentNotification;
use App\Services\AlertDetectionService;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\IncidentMetrics;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidentCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private IncidentManager $manager;

    private User $admin;

    private User $viewer;

    private Server $server;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        AlertRule::query()->forceDelete();
        $this->manager = app(IncidentManager::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create();
        $this->server = Server::query()->create(['name' => 'web-01', 'hostname' => 'web-01', 'ip_address' => '93.184.215.14']);
        $this->domain = Domain::query()->create(['name' => 'example.com']);
    }

    private function dependsOn(Domain $domain, Server $server): void
    {
        ResourceDependency::query()->create([
            'dependent_type' => 'domain', 'dependent_id' => $domain->id,
            'dependency_type' => 'server', 'dependency_id' => $server->id,
        ]);
    }

    private function serverDown(?Server $server = null): Incident
    {
        $server ??= $this->server;

        return $this->manager->trigger(new Signal("alert:1:{$server->id}:agent_offline:-", 'agent', 'critical', "{$server->name} reageert niet", null, $server));
    }

    private function serverCpu(): Incident
    {
        return $this->manager->trigger(new Signal("alert:2:{$this->server->id}:cpu_percent:-", 'alert', 'warning', 'CPU hoog', null, $this->server));
    }

    private function websiteDown(string $error = 'connection_failed', ?Domain $domain = null): Incident
    {
        $domain ??= $this->domain;

        return $this->manager->trigger(new Signal("domain:{$domain->id}:http", 'http', 'critical', "{$domain->name} is onbereikbaar", null, $domain, ['error' => $error]));
    }

    public function test_dependent_website_outage_is_grouped_under_server_outage(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $root = $this->serverDown();

        $this->travel(2)->minutes();
        $child = $this->websiteDown();

        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame('dependency', $child->correlation['reason_code']);
        $this->assertSame(['domain:'.$this->domain->id, 'server:'.$this->server->id], $child->correlation['path']);
        $this->assertStringContainsString('web-01', $child->correlation['reason']);

        // Both timelines record the grouping; the child keeps its own record.
        $this->assertTrue($child->events()->where('type', 'correlated')->exists());
        $this->assertTrue($root->events()->where('type', 'child_added')->exists());
        $this->assertTrue($child->events()->where('type', 'opened')->exists());
        $this->assertTrue(IncidentImpact::query()->where('incident_id', $root->id)->where('resource_type', 'domain')->where('resource_id', $this->domain->id)->exists());

        // One notification per underlying problem.
        Notification::assertSentToTimes($this->admin, IncidentNotification::class, 1);
        $this->assertTrue(NotificationLog::query()->where('incident_id', $child->id)->where('status', 'suppressed')->exists());
    }

    public function test_simultaneous_incidents_without_a_dependency_are_not_grouped(): void
    {
        $this->serverDown();
        $child = $this->websiteDown();

        $this->assertNull($child->parent_id);
        Notification::assertSentToTimes($this->admin, IncidentNotification::class, 2);
    }

    public function test_resource_alerts_do_not_explain_outages(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $this->serverCpu();

        $this->assertNull($this->websiteDown()->parent_id);
    }

    public function test_page_specific_failures_are_not_grouped_under_a_server_outage(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $this->serverDown();

        $this->assertNull($this->websiteDown('unexpected_status')->parent_id);
    }

    public function test_root_detected_later_adopts_recent_dependent_incidents(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $child = $this->websiteDown();

        // The agent is only declared offline after a few minutes.
        $this->travel(3)->minutes();
        $root = $this->serverDown();

        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertSame('dependency', $child->fresh()->correlation['reason_code']);
    }

    public function test_old_dependent_incidents_outside_the_window_are_not_adopted(): void
    {
        config(['serverscope.incidents.correlation_window_minutes' => 30]);
        $this->dependsOn($this->domain, $this->server);
        $child = $this->websiteDown();

        $this->travel(2)->hours();
        $this->serverDown();

        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_transitive_dependencies_group_under_the_root(): void
    {
        $db = Server::query()->create(['name' => 'db-01', 'hostname' => 'db-01']);
        $this->dependsOn($this->domain, $this->server);
        ResourceDependency::query()->create(['dependent_type' => 'server', 'dependent_id' => $this->server->id, 'dependency_type' => 'server', 'dependency_id' => $db->id]);

        $root = $this->serverDown($db);
        $child = $this->websiteDown();

        $this->assertSame($root->id, $child->parent_id);
        $this->assertCount(3, $child->correlation['path']);
    }

    public function test_invalid_certificate_groups_tls_failures_of_the_same_website_only(): void
    {
        $ssl = $this->manager->trigger(new Signal("domain:{$this->domain->id}:ssl", 'ssl', 'critical', 'Ongeldig certificaat', null, $this->domain, ['ssl_status' => 'expired']));

        $tls = $this->websiteDown('tls_error');
        $this->assertSame($ssl->id, $tls->parent_id);
        $this->assertSame('same_resource', $tls->correlation['reason_code']);

        $tls->forceFill(['status' => 'resolved', 'active_fingerprint' => null, 'parent_id' => null])->save();
        $other = Domain::query()->create(['name' => 'other.example']);
        $this->assertNull($this->websiteDown('tls_error', $other)->parent_id, 'another website is not explained by this certificate');
    }

    public function test_children_recover_on_their_own_evidence_and_notify_once_parent_resolved(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $root = $this->serverDown();
        $child = $this->websiteDown();

        // Child recovers while the root is active: no separate recovery mail.
        $this->manager->recover("domain:{$this->domain->id}:http", 'Weer bereikbaar');
        $this->assertSame('resolved', $child->fresh()->status);
        $this->assertSame('active', $root->fresh()->isActive() ? 'active' : 'resolved');
        $this->assertTrue(NotificationLog::query()->where('incident_id', $child->id)->where('kind', 'resolved')->where('status', 'suppressed')->exists());
    }

    public function test_admin_can_detach_a_grouped_incident(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $this->serverDown();
        $child = $this->websiteDown();

        $this->actingAs($this->viewer)->post(route('incidents.detach', $child))->assertForbidden();
        $this->actingAs($this->admin)->post(route('incidents.detach', $child))->assertSessionHas('success');

        $this->assertNull($child->fresh()->parent_id);
        $this->assertTrue($child->events()->where('type', 'detached')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.detached']);
    }

    public function test_reopened_incident_is_correlated_again(): void
    {
        config(['serverscope.incidents.reopen_window_minutes' => 60]);
        $this->dependsOn($this->domain, $this->server);
        $root = $this->serverDown();
        $child = $this->websiteDown();
        $this->manager->recover("alert:1:{$this->server->id}:agent_offline:-", 'Online');
        $this->manager->recover("domain:{$this->domain->id}:http", 'Bereikbaar');

        $this->travel(5)->minutes();
        $reopened = $this->websiteDown();

        $this->assertTrue($reopened->is($child));
        $this->assertNull($reopened->parent_id, 'the resolved server incident no longer explains it');
        $this->assertSame('resolved', $root->fresh()->status);
    }

    public function test_incident_page_shows_grouping_and_impacts(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $root = $this->serverDown();
        $child = $this->websiteDown();

        $this->actingAs($this->viewer)->get(route('incidents.show', $root))
            ->assertOk()->assertSee('Gerelateerde incidenten')->assertSee('Getroffen resources')->assertSee($child->title);
        $this->actingAs($this->viewer)->get(route('incidents.show', $child))
            ->assertOk()->assertSee('Gegroepeerd')->assertSee('incident #'.$root->id);
        $this->actingAs($this->viewer)->get(route('incidents.index'))
            ->assertOk()->assertSee('gegroepeerd onder #'.$root->id);
    }

    public function test_escalation_skips_grouped_incidents(): void
    {
        $this->dependsOn($this->domain, $this->server);
        $root = $this->serverDown();
        $child = $this->websiteDown();

        $this->travel(20)->minutes();
        $this->artisan('serverscope:escalate-incidents')->assertSuccessful();

        $this->assertNotNull($root->fresh()->escalated_at);
        $this->assertNull($child->fresh()->escalated_at);
    }

    public function test_notifications_can_be_disabled_per_resource(): void
    {
        $this->domain->forceFill(['notifications_enabled' => false])->save();
        $incident = $this->websiteDown();

        Notification::assertNothingSent();
        $this->assertSame('open', $incident->status);
        $this->assertTrue(NotificationLog::query()->where('incident_id', $incident->id)->where('status', 'suppressed')->exists());
    }

    // ---------- dependencies ----------

    public function test_admin_manages_dependencies_with_validation(): void
    {
        $this->actingAs($this->viewer)->get(route('dependencies.index'))->assertOk();
        $this->actingAs($this->viewer)->post(route('dependencies.store'), ['dependent' => 'domain:'.$this->domain->id, 'dependency' => 'server:'.$this->server->id])->assertForbidden();

        $this->actingAs($this->admin)->post(route('dependencies.store'), ['dependent' => 'domain:'.$this->domain->id, 'dependency' => 'server:'.$this->server->id, 'label' => 'draait op'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('resource_dependencies', ['dependent_type' => 'domain', 'dependency_type' => 'server', 'label' => 'draait op']);

        // Self reference, cycles and unknown resources are rejected.
        $this->actingAs($this->admin)->post(route('dependencies.store'), ['dependent' => 'server:'.$this->server->id, 'dependency' => 'server:'.$this->server->id])->assertSessionHasErrors('dependency');
        $this->actingAs($this->admin)->post(route('dependencies.store'), ['dependent' => 'server:'.$this->server->id, 'dependency' => 'domain:'.$this->domain->id])->assertSessionHasErrors('dependency');
        $this->actingAs($this->admin)->post(route('dependencies.store'), ['dependent' => 'server:999', 'dependency' => 'domain:'.$this->domain->id])->assertSessionHasErrors('dependent');
        $this->actingAs($this->admin)->post(route('dependencies.store'), ['dependent' => 'user:1', 'dependency' => 'domain:'.$this->domain->id])->assertSessionHasErrors('dependent');

        $this->actingAs($this->admin)->get(route('dependencies.index'))->assertOk()->assertSee('web-01')->assertSee('example.com');

        $dependency = ResourceDependency::query()->firstOrFail();
        $this->actingAs($this->viewer)->delete(route('dependencies.destroy', $dependency))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('dependencies.destroy', $dependency))->assertSessionHas('success');
        $this->assertDatabaseCount('resource_dependencies', 0);
    }

    public function test_inferred_relations_are_suggested_but_not_used_for_grouping(): void
    {
        $this->domain->dnsSnapshots()->create(['record_type' => 'A', 'records' => ['93.184.215.14'], 'hash' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->admin)->get(route('domains.show', $this->domain))
            ->assertOk()->assertSee('Voorgestelde relaties')->assertSee('93.184.215.14');
        $this->actingAs($this->admin)->get(route('servers.show', $this->server))
            ->assertOk()->assertSee('Voorgestelde relaties');

        $this->serverDown();
        $this->assertNull($this->websiteDown()->parent_id);
    }

    // ---------- lifecycle ----------

    public function test_recovery_requires_the_configured_number_of_good_samples(): void
    {
        $rule = AlertRule::query()->create(['name' => 'CPU', 'metric' => 'cpu_percent', 'threshold' => 90, 'operator' => '>=', 'duration_minutes' => 1, 'recovery_samples' => 3, 'is_enabled' => true, 'severity' => 'high']);
        $service = app(AlertDetectionService::class);
        $sample = fn (float $cpu) => $this->server->metricSamples()->create(['cpu_percent' => $cpu, 'collected_at' => now()]);

        foreach ([95, 96, 97] as $cpu) {
            $service->evaluate($sample($cpu));
            $this->travel(1)->minutes();
        }

        $alert = Alert::query()->where('alert_rule_id', $rule->id)->firstOrFail();
        $this->assertSame('active', $alert->status);
        $this->assertSame('high', $alert->incident->severity);

        $service->evaluate($sample(10));
        $this->travel(1)->minutes();
        $service->evaluate($sample(12));
        $this->travel(1)->minutes();
        $this->assertSame('active', $alert->fresh()->status, 'two good samples are not enough');

        // A relapse resets the streak.
        $service->evaluate($sample(95));
        $this->travel(1)->minutes();
        $this->assertSame(0, $alert->fresh()->recovery_streak);

        foreach ([10, 11, 12] as $cpu) {
            $service->evaluate($sample($cpu));
            $this->travel(1)->minutes();
        }

        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertSame('resolved', $alert->incident->fresh()->status);
    }

    public function test_mttd_is_measured_from_the_first_failed_check(): void
    {
        $started = now()->subMinutes(10);
        $incident = $this->manager->trigger(new Signal('domain:x:http', 'http', 'critical', 'Down', null, $this->domain, [], null, $started));

        $this->assertSame(600, $incident->timeToDetect());
        $summary = app(IncidentMetrics::class)->summary(now()->subDay(), now()->addMinute());
        $this->assertSame(600, $summary['mttd_seconds']);

        // A start in the future is not trusted.
        $future = $this->manager->trigger(new Signal('domain:y:http', 'http', 'critical', 'Down', null, $this->domain, [], null, now()->addHour()));
        $this->assertSame(0, $future->timeToDetect());
    }

    public function test_high_severity_is_supported_end_to_end(): void
    {
        $highOnly = User::factory()->create(['notify_in_app' => true, 'notify_min_severity' => 'high']);

        $this->manager->trigger(new Signal('s:1', 'manual', 'warning', 'Medium', null));
        Notification::assertNotSentTo($highOnly, IncidentNotification::class);

        $incident = $this->manager->trigger(new Signal('s:2', 'manual', 'high', 'High', null));
        Notification::assertSentTo($highOnly, IncidentNotification::class);

        $this->actingAs($this->admin)->get(route('incidents.show', $incident))->assertOk()->assertSee('Hoog');
        $this->actingAs($this->admin)->post(route('incidents.store'), ['title' => 'Handmatig', 'severity' => 'high'])->assertRedirect();
        $this->assertDatabaseHas('incidents', ['title' => 'Handmatig', 'severity' => 'high']);
    }

    public function test_manual_resolution_requires_a_summary(): void
    {
        $incident = $this->websiteDown();

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'resolved'])->assertSessionHasErrors('notes');
        $this->assertSame('open', $incident->fresh()->status);

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'resolved', 'notes' => 'Nginx herstart na configuratiefout.'])->assertSessionHas('success');
        $this->assertSame('Nginx herstart na configuratiefout.', $incident->fresh()->resolution_notes);
    }
}
