<?php

namespace Tests\Feature\Tenancy;

use App\Events\DnsRecordsChanged;
use App\Jobs\RunDomainCheck;
use App\Jobs\RunPageCheck;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Notifications\DnsChangeNotification;
use App\Notifications\IncidentNotification;
use App\Services\Incidents\CorrelationEngine;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Reports\DashboardOverview;
use App\Services\SystemHealth;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Scheduled commands and jobs run without a signed-in user: they must
 * keep processing every team, with each team's own recipients, rules
 * and settings.
 */
class BackgroundTenancyTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    private IncidentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createTwoTeams();
        // Forget the notifications sent while building the fixture.
        Notification::fake();
        $this->manager = app(IncidentManager::class);
    }

    public function test_an_incident_only_notifies_members_of_its_own_team(): void
    {
        Queue::fake();
        Notification::fake();

        $this->manager->trigger(new Signal('alert:x:'.$this->a['server']->id, 'alert', 'critical', 'CPU op alpha-server', null, $this->a['server']));

        Notification::assertSentTo($this->a['admin'], IncidentNotification::class);
        Notification::assertSentTo($this->a['member'], IncidentNotification::class);
        Notification::assertNotSentTo($this->b['admin'], IncidentNotification::class);
        Notification::assertNotSentTo($this->b['member'], IncidentNotification::class);

        // Only team A's webhook receives the event.
        $endpoints = WebhookDelivery::query()->where('event', 'incident.opened')->pluck('webhook_endpoint_id')->all();
        $this->assertSame([$this->a['webhook']->id], $endpoints);
    }

    public function test_dns_and_page_changes_only_notify_their_own_team(): void
    {
        Queue::fake();
        DnsRecordsChanged::dispatch($this->b['domain'], [$this->b['dnsChange']]);

        Notification::assertSentTo($this->b['admin'], DnsChangeNotification::class);
        Notification::assertNotSentTo($this->a['admin'], DnsChangeNotification::class);
        $this->assertSame([$this->b['webhook']->id], WebhookDelivery::query()->where('event', 'dns.changed')->pluck('webhook_endpoint_id')->all());
    }

    public function test_agent_heartbeats_are_evaluated_for_every_team_with_its_own_rules(): void
    {
        // Each team has its own (migration-created or team-created) offline rule.
        $ruleA = AlertRule::withoutGlobalScopes()->where('team_id', $this->a['team']->id)->where('metric', 'agent_offline')->firstOrFail();
        $ruleB = AlertRule::withoutGlobalScopes()->where('team_id', $this->b['team']->id)->where('metric', 'agent_offline')->firstOrFail();
        $ruleB->forceFill(['threshold' => 60])->save();

        Server::withoutGlobalScopes()->whereKey([$this->a['server']->id, $this->b['server']->id])->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('serverscope:check-agents')->assertSuccessful();

        $alerts = Alert::withoutGlobalScopes()->where('metric', 'agent_offline')->where('status', 'active')->get();
        $this->assertSame([$this->a['server']->id], $alerts->pluck('server_id')->all(), 'team B allows 60 minutes of silence');
        $this->assertSame($ruleA->id, $alerts->first()->alert_rule_id);
        $this->assertSame($this->a['team']->id, $alerts->first()->team_id);

        Notification::assertSentTo($this->a['admin'], IncidentNotification::class);
        Notification::assertNotSentTo($this->b['admin'], IncidentNotification::class);

        $this->travel(61)->minutes();
        $this->artisan('serverscope:check-agents')->assertSuccessful();
        $this->assertSame($ruleB->id, Alert::withoutGlobalScopes()->where('server_id', $this->b['server']->id)->where('metric', 'agent_offline')->value('alert_rule_id'));
        Notification::assertSentTo($this->b['admin'], IncidentNotification::class);
    }

    public function test_check_dispatchers_and_analysis_cover_every_team(): void
    {
        Queue::fake();

        $this->artisan('serverscope:dispatch-domain-checks')->assertSuccessful();
        $this->artisan('serverscope:dispatch-page-checks')->assertSuccessful();

        Queue::assertPushed(RunDomainCheck::class, fn ($job) => $job->domainId === $this->a['domain']->id);
        Queue::assertPushed(RunDomainCheck::class, fn ($job) => $job->domainId === $this->b['domain']->id);
        Queue::assertPushed(RunPageCheck::class, fn ($job) => $job->pageId === $this->a['page']->id);
        Queue::assertPushed(RunPageCheck::class, fn ($job) => $job->pageId === $this->b['page']->id);

        $this->artisan('serverscope:analyze')->assertSuccessful()->expectsOutputToContain('fouten: 0');
        $this->artisan('serverscope:prune')->assertSuccessful();
    }

    public function test_commands_still_see_every_team_after_a_web_request(): void
    {
        Queue::fake();

        // A request sets the tenant of team A; it must not linger.
        $this->actingAs($this->a['admin'])->get(route('dashboard'))->assertOk();
        $this->assertNull(Tenant::id());

        $this->artisan('serverscope:dispatch-domain-checks')->assertSuccessful();
        Queue::assertPushed(RunDomainCheck::class, fn ($job) => $job->domainId === $this->b['domain']->id);
    }

    public function test_escalation_runs_for_every_team_and_config_contacts_only_get_the_default_team(): void
    {
        config(['serverscope.escalation.after_minutes' => ['critical' => 15], 'serverscope.escalation.emails' => ['oncall@example.com']]);

        $default = Team::query()->where('is_default', true)->firstOrFail();
        $server = Server::forceCreate(['team_id' => $default->id, 'name' => 'eigen-server', 'hostname' => 'eigen.test']);
        $own = $this->manager->trigger(new Signal('esc:default', 'manual', 'critical', 'Eigen storing', null, $server));

        $this->travel(16)->minutes();
        $this->artisan('serverscope:escalate-incidents')->assertSuccessful();

        foreach ([$this->a['incident'], $this->b['incident'], $own] as $incident) {
            $this->assertNotNull(Incident::withoutGlobalScopes()->find($incident->id)->escalated_at);
        }

        Notification::assertSentTo($this->b['admin'], IncidentNotification::class, fn ($n) => $n->kind === 'unacknowledged' && $n->incident->id === $this->b['incident']->id);
        Notification::assertNotSentTo($this->a['admin'], IncidentNotification::class, fn ($n) => $n->incident->id === $this->b['incident']->id);
        Notification::assertSentOnDemandTimes(IncidentNotification::class, 1);
        Notification::assertSentOnDemand(IncidentNotification::class, fn ($n) => $n->incident->id === $own->id);
    }

    public function test_correlation_never_connects_two_teams(): void
    {
        // Team A's website resolves to team B's server address: that must
        // not even be suggested as a dependency.
        $this->a['domain']->dnsSnapshots()->create(['record_type' => 'A', 'records' => ['93.184.216.20'], 'hash' => str_repeat('0', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // Even a corrupt dependency row that points across teams is ignored.
        ResourceDependency::withoutGlobalScopes()->insert(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $this->a['domain']->id, 'dependency_type' => 'server', 'dependency_id' => $this->b['server']->id, 'created_at' => now(), 'updated_at' => now()]);

        $root = $this->manager->trigger(new Signal('alert:1:'.$this->b['server']->id.':agent_offline:-', 'agent', 'critical', 'bravo-server reageert niet', null, $this->b['server']));
        $child = $this->manager->trigger(new Signal('domain:'.$this->a['domain']->id.':http', 'http', 'critical', 'alpha-shop onbereikbaar', null, $this->a['domain'], ['error' => 'timeout']));

        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame(0, Incident::withoutGlobalScopes()->where('parent_id', $root->id)->count());

        $graph = app(\App\Services\Dependencies\DependencyGraph::class);
        $this->assertArrayNotHasKey('server:'.$this->b['server']->id, $graph->ancestors('domain', $this->a['domain']->id, teamId: $this->a['team']->id));
        $this->assertSame([], array_filter($graph->inferred($this->a['domain']), fn ($s) => $s['id'] === $this->b['server']->id && $s['type'] === 'server'));
    }

    public function test_correlation_still_works_within_a_team(): void
    {
        $root = $this->manager->trigger(new Signal('alert:1:'.$this->a['server']->id.':agent_offline:-', 'agent', 'critical', 'alpha-server reageert niet', null, $this->a['server']));
        $child = $this->manager->trigger(new Signal('domain:'.$this->a['domain']->id.':http', 'http', 'critical', 'alpha-shop onbereikbaar', null, $this->a['domain'], ['error' => 'timeout']));

        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertSame($this->a['team']->id, $child->fresh()->team_id);
    }

    public function test_cached_summaries_are_per_team(): void
    {
        $this->b['domain']->checks()->createMany([
            ['type' => 'http', 'result' => 'down', 'success' => false, 'checked_at' => now()->subMinutes(3)],
            ['type' => 'http', 'result' => 'down', 'success' => false, 'checked_at' => now()->subMinutes(2)],
        ]);

        $a = Tenant::run($this->a['team'], fn () => app(DashboardOverview::class)->data()['trends']);
        $b = Tenant::run($this->b['team'], fn () => app(DashboardOverview::class)->data()['trends']);

        $this->assertSame(1, $a['checks_24h']);
        $this->assertSame(100.0, (float) $a['uptime_24h']);
        $this->assertSame(3, $b['checks_24h']);

        // The sidebar summary of system health is cached per team too.
        \App\Models\NotificationLog::withoutGlobalScopes()->where('team_id', $this->a['team']->id)->delete();
        $this->a['webhook']->deliveries()->delete();
        SystemHealth::beat();
        $this->b['page']->forceFill(['next_check_at' => now()->subHour()])->save();
        $health = app(SystemHealth::class);
        $this->assertSame('healthy', Tenant::run($this->a['team'], fn () => $health->summary($this->a['team']->id)));
        $this->assertSame('warning', Tenant::run($this->b['team'], fn () => $health->summary($this->b['team']->id)));
    }
}
