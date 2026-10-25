<?php

namespace Tests\Feature;

use App\Events\IncidentEscalated;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Events\IncidentSeverityIncreased;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\IncidentMetrics;
use App\Services\Incidents\Signal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private IncidentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        AlertRule::query()->forceDelete();
        $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Admin']);
        $this->viewer = User::factory()->create(['is_admin' => false, 'name' => 'Viewer']);
        $this->manager = app(IncidentManager::class);
    }

    private function signal(string $fingerprint = 'test:1', string $severity = 'warning', ?string $key = null, $subject = null): Signal
    {
        return new Signal($fingerprint, 'manual', $severity, "Probleem {$fingerprint}", 'Beschrijving', $subject, [], $key);
    }

    // ---------- engine ----------

    public function test_repeated_signals_update_one_incident(): void
    {
        Event::fake([IncidentOpened::class]);

        $first = $this->manager->trigger($this->signal());
        $this->travel(1)->minutes();
        $second = $this->manager->trigger($this->signal());
        $this->manager->trigger($this->signal());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(3, $first->fresh()->occurrence_count);
        $this->assertTrue($first->fresh()->last_occurrence_at->gt($first->opened_at));
        Event::assertDispatchedTimes(IncidentOpened::class, 1);
    }

    public function test_database_prevents_two_active_incidents_for_one_fingerprint(): void
    {
        $this->manager->trigger($this->signal());

        $this->expectException(QueryException::class);

        Incident::query()->create([
            'fingerprint' => 'test:1', 'active_fingerprint' => 'test:1', 'source' => 'manual',
            'severity' => 'warning', 'status' => 'open', 'title' => 'dup', 'opened_at' => now(), 'last_occurrence_at' => now(),
        ]);
    }

    public function test_severity_increase_and_escalation_keys_are_announced(): void
    {
        Event::fake([IncidentSeverityIncreased::class, IncidentEscalated::class]);

        $this->manager->trigger($this->signal(severity: 'warning', key: 'a'));
        $this->manager->trigger($this->signal(severity: 'warning', key: 'a'));
        Event::assertNotDispatched(IncidentEscalated::class);

        $this->manager->trigger($this->signal(severity: 'warning', key: 'b'));
        Event::assertDispatchedTimes(IncidentEscalated::class, 1);

        $incident = $this->manager->trigger($this->signal(severity: 'critical', key: 'b'));
        Event::assertDispatchedTimes(IncidentSeverityIncreased::class, 1);
        $this->assertSame('critical', $incident->severity);

        // Lower severity signals never downgrade.
        $this->assertSame('critical', $this->manager->trigger($this->signal(severity: 'warning', key: 'b'))->severity);
    }

    public function test_recovery_and_reopen_window(): void
    {
        config(['serverscope.incidents.reopen_window_minutes' => 15]);

        $incident = $this->manager->trigger($this->signal());
        $this->manager->recover('test:1', 'Hersteld');
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNull($this->manager->recover('test:1', 'Nogmaals'), 'recovering twice is a no-op');

        $this->travel(10)->minutes();
        $reopened = $this->manager->trigger($this->signal());
        $this->assertTrue($reopened->is($incident));
        $this->assertSame(1, $reopened->reopened_count);

        $this->manager->recover('test:1', 'Hersteld');
        $this->travel(16)->minutes();
        $new = $this->manager->trigger($this->signal());
        $this->assertFalse($new->is($incident));
    }

    public function test_closed_incidents_are_never_reopened_automatically(): void
    {
        $incident = $this->manager->trigger($this->signal());
        $this->manager->transition($incident, 'resolved', $this->admin);
        $this->manager->transition($incident, 'closed', $this->admin);

        $new = $this->manager->trigger($this->signal());

        $this->assertFalse($new->is($incident));
        $this->assertSame('closed', $incident->fresh()->status);
    }

    // ---------- server alerts ----------

    public function test_server_alerts_open_and_resolve_incidents(): void
    {
        Event::fake([IncidentOpened::class, IncidentResolved::class]);
        $rule = AlertRule::create(['name' => 'Hoge CPU', 'metric' => 'cpu_percent', 'threshold' => 80, 'operator' => '>=', 'severity' => 'critical', 'duration_minutes' => 1, 'is_enabled' => true]);
        $server = Server::create(['name' => 'Web 1', 'hostname' => 'web1.example.test']);

        $alert = Alert::create(['server_id' => $server->id, 'alert_rule_id' => $rule->id, 'metric' => 'cpu_percent', 'observed_value' => 95, 'threshold' => 80, 'status' => 'active', 'triggered_at' => now()]);

        $incident = Incident::query()->sole();
        $this->assertSame($incident->id, $alert->fresh()->incident_id);
        $this->assertSame('Hoge CPU op Web 1', $incident->title);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('alert', $incident->source);
        $this->assertTrue($incident->subject->is($server));

        $alert->update(['observed_value' => 96]);
        $this->assertSame(1, $incident->fresh()->occurrence_count, 'value updates do not touch the incident');

        $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'observed_value' => 40]);
        $this->assertSame('resolved', $incident->fresh()->status);
        Event::assertDispatchedTimes(IncidentResolved::class, 1);
    }

    public function test_disabling_a_rule_resolves_incidents_without_recovery_notification(): void
    {
        Event::fake([IncidentResolved::class]);
        $rule = AlertRule::create(['name' => 'Offline', 'metric' => 'agent_offline', 'threshold' => 5, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);
        $server = Server::create(['name' => 'Web 1', 'hostname' => 'web1.example.test', 'last_seen_at' => now()->subHour()]);
        app(\App\Services\AgentHeartbeatService::class)->check();
        $this->assertSame('agent', Incident::query()->sole()->source);

        $this->actingAs($this->admin)->put(route('alert-rules.update', $rule), [
            'name' => 'Offline', 'metric' => 'agent_offline', 'threshold' => 5, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => false,
        ])->assertRedirect();

        $this->assertSame('resolved', Incident::query()->sole()->status);
        Event::assertNotDispatched(IncidentResolved::class);
    }

    // ---------- lifecycle over HTTP ----------

    public function test_acknowledge_investigate_resolve_and_close(): void
    {
        $incident = $this->manager->trigger($this->signal());
        $this->travel(5)->minutes();

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'acknowledged'])->assertSessionHas('success');
        $incident->refresh();
        $this->assertSame('acknowledged', $incident->status);
        $this->assertSame($this->viewer->id, $incident->acknowledged_by);
        $this->assertSame(300, $incident->timeToAcknowledge());

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'investigating'])->assertSessionHas('success');
        $this->assertSame(300, $incident->fresh()->timeToAcknowledge(), 'first acknowledgement is kept');

        $this->travel(10)->minutes();
        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'resolved', 'notes' => 'Disk opgeschoond'])->assertSessionHas('success');
        $incident->refresh();
        $this->assertSame('resolved', $incident->status);
        $this->assertSame($this->viewer->id, $incident->resolved_by);
        $this->assertSame('Disk opgeschoond', $incident->resolution_notes);
        $this->assertNull($incident->active_fingerprint);

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'closed'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('incidents.transition', $incident), ['status' => 'closed'])->assertSessionHas('success');
        $this->assertSame('closed', $incident->fresh()->status);

        $types = $incident->events()->pluck('type')->all();
        $this->assertSame(['opened', 'acknowledged', 'investigating', 'resolved', 'closed'], $types);
        $this->assertSame($this->viewer->id, $incident->events()->where('type', 'acknowledged')->value('user_id'));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $incident = $this->manager->trigger($this->signal());
        $this->manager->transition($incident, 'resolved', $this->admin);

        $this->actingAs($this->admin)->post(route('incidents.transition', $incident), ['status' => 'acknowledged'])->assertSessionHas('error');
        $this->assertSame('resolved', $incident->fresh()->status);

        $this->actingAs($this->admin)->post(route('incidents.transition', $incident), ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    public function test_manual_reopen_requires_admin_and_no_other_active_incident(): void
    {
        $incident = $this->manager->trigger($this->signal());
        $this->manager->transition($incident, 'resolved', $this->admin);

        $this->actingAs($this->viewer)->post(route('incidents.transition', $incident), ['status' => 'open'])->assertForbidden();

        config(['serverscope.incidents.reopen_window_minutes' => 0]);
        $other = $this->manager->trigger($this->signal());
        $this->assertFalse($other->is($incident));

        $this->actingAs($this->admin)->post(route('incidents.transition', $incident), ['status' => 'open'])->assertSessionHas('error');

        $this->manager->recover('test:1', 'ok');
        $this->actingAs($this->admin)->post(route('incidents.transition', $incident), ['status' => 'open'])->assertSessionHas('success');
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_assignment_comments_and_severity(): void
    {
        $incident = $this->manager->trigger($this->signal());

        $this->actingAs($this->viewer)->post(route('incidents.assign', $incident), ['assigned_to' => $this->viewer->id])->assertSessionHas('success');
        $this->assertSame($this->viewer->id, $incident->fresh()->assigned_to);

        $this->actingAs($this->viewer)->post(route('incidents.comment', $incident), ['message' => 'Logs bekeken, <script>x</script>'])->assertSessionHas('success');
        $comment = $incident->events()->where('type', 'comment')->sole();
        $this->assertSame($this->viewer->id, $comment->user_id);

        $this->actingAs($this->viewer)->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('Logs bekeken')
            ->assertDontSee('<script>x</script>', false);

        $this->actingAs($this->viewer)->post(route('incidents.severity', $incident), ['severity' => 'critical'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('incidents.severity', $incident), ['severity' => 'critical'])->assertSessionHas('success');
        $this->assertSame('critical', $incident->fresh()->severity);

        $this->actingAs($this->viewer)->post(route('incidents.assign', $incident), ['assigned_to' => 99999])->assertSessionHasErrors('assigned_to');
    }

    public function test_manual_incidents_are_admin_only(): void
    {
        $domain = Domain::create(['name' => 'example.com']);

        $this->actingAs($this->viewer)->get(route('incidents.create'))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('incidents.store'), ['title' => 'x', 'severity' => 'warning'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('incidents.store'), [
            'title' => 'Storing bij hostingprovider', 'severity' => 'critical', 'subject' => 'domain:'.$domain->id, 'assigned_to' => $this->viewer->id,
        ])->assertRedirect();

        $incident = Incident::query()->sole();
        $this->assertSame('manual', $incident->source);
        $this->assertTrue($incident->subject->is($domain));
        $this->assertSame($this->viewer->id, $incident->assigned_to);
        $this->assertStringStartsWith('manual:', $incident->fingerprint);
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.created']);

        $this->actingAs($this->admin)->post(route('incidents.store'), ['title' => 'x', 'severity' => 'warning', 'subject' => 'user:1'])->assertSessionHasErrors('subject');
    }

    public function test_index_filters_and_pagination(): void
    {
        $server = Server::create(['name' => 'Web 1', 'hostname' => 'web1.example.test']);
        $critical = $this->manager->trigger($this->signal('a', 'critical', subject: $server));
        $warning = $this->manager->trigger($this->signal('b', 'warning'));
        $resolved = $this->manager->trigger($this->signal('c'));
        $this->manager->recover('c', 'ok');
        $this->manager->assign($warning, $this->viewer, $this->admin);

        $ids = fn (array $query) => $this->actingAs($this->viewer)->get(route('incidents.index', $query))->assertOk()->viewData('incidents')->pluck('id')->sort()->values()->all();

        $this->assertSame([$critical->id, $warning->id], $ids([]));
        $this->assertSame([$resolved->id], $ids(['state' => 'resolved']));
        $this->assertSame([$critical->id], $ids(['severity' => 'critical']));
        $this->assertSame([$critical->id], $ids(['server' => $server->id]));
        $this->assertSame([$warning->id], $ids(['assigned' => 'me']));
        $this->assertSame([$critical->id, $warning->id, $resolved->id], $ids(['state' => 'all']));
        $this->assertSame([$critical->id], $ids(['q' => 'Probleem a']));

        foreach (range(1, 25) as $i) {
            $this->manager->trigger($this->signal("bulk:{$i}"));
        }

        $page = $this->actingAs($this->viewer)->get(route('incidents.index'))->viewData('incidents');
        $this->assertSame(20, $page->count());
        $this->assertSame(27, $page->total());
    }

    public function test_mtta_and_mttr_are_calculated_from_timestamps(): void
    {
        $base = now()->subDays(3)->startOfHour();
        $make = fn (array $attrs) => Incident::query()->create([
            'fingerprint' => uniqid(), 'source' => 'manual', 'severity' => 'warning', 'title' => 'x',
            'opened_at' => $base, 'last_occurrence_at' => $base, ...$attrs,
        ]);

        $make(['status' => 'resolved', 'acknowledged_at' => $base->copy()->addMinutes(10), 'resolved_at' => $base->copy()->addHour()]);
        $make(['status' => 'closed', 'acknowledged_at' => $base->copy()->addMinutes(20), 'resolved_at' => $base->copy()->addHours(3)]);
        $make(['status' => 'open']); // neither acknowledged nor resolved: excluded
        $make(['status' => 'resolved', 'resolved_at' => $base->copy()->addMinutes(30)]); // auto-recovered, never acknowledged

        $summary = app(IncidentMetrics::class)->summary(now()->subDays(30), now());

        $this->assertSame(4, $summary['opened']);
        $this->assertSame(2, $summary['acknowledged']);
        $this->assertSame(15 * 60, $summary['mtta_seconds']);
        $this->assertSame(3, $summary['resolved']);
        $this->assertSame((60 + 180 + 30) * 60 / 3, $summary['mttr_seconds']);

        $empty = app(IncidentMetrics::class)->summary(now()->subYears(2), now()->subYear());
        $this->assertNull($empty['mtta_seconds']);
    }

    public function test_detail_page_shows_timeline_and_related_checks(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        $domain->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'error_code' => 'timeout', 'message' => 'Time-out bereikt', 'checked_at' => now()]);
        $incident = $this->manager->trigger(new Signal('domain:'.$domain->id.':http', 'http', 'critical', 'example.com is onbereikbaar', 'Time-out', $domain));

        $this->actingAs($this->viewer)->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee(['example.com is onbereikbaar', 'Incident geopend', 'Time-out bereikt', 'Mislukte controles']);
    }

    public function test_sidebar_shows_active_incident_count(): void
    {
        $this->manager->trigger($this->signal('x'));
        $this->manager->trigger($this->signal('y'));

        $this->actingAs($this->viewer)->get(route('dashboard'))->assertSee('<span class="nav-count ">2</span>', false);
    }
}
