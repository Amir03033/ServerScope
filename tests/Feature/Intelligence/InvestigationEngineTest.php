<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\MetricSample;
use App\Models\ResourceDependency;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\Collectors\ServiceCollector;
use App\Services\Incidents\Evidence\EvidenceScope;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Intelligence\InvestigationRunner;
use App\Services\Intelligence\Investigations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * The deterministic engine on real data: evidence, hypotheses with their
 * confidence rules, impact and the stored snapshot.
 */
class InvestigationEngineTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private CarbonImmutable $onset;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['serverscope.intelligence.auto_triggers' => []]);
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
        $this->onset = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Samples every 5 minutes for a server in [$from, $to). */
    private function samples(string $team, string $from, string $to, float $cpu = 10, ?string $spikeFrom = null, ?string $rebootAt = null): void
    {
        $server = $this->{$team}['server'];
        MetricSample::query()->where('server_id', $server->id)->delete();
        $rows = [];
        $spike = $spikeFrom ? CarbonImmutable::parse($spikeFrom, 'UTC') : null;
        $reboot = $rebootAt ? CarbonImmutable::parse($rebootAt, 'UTC') : null;

        for ($at = CarbonImmutable::parse($from, 'UTC'); $at->lt(CarbonImmutable::parse($to, 'UTC')); $at = $at->addMinutes(5)) {
            $uptime = $reboot && $at->gte($reboot) ? $at->diffInSeconds($reboot, true) + 60 : 900000 + $at->getTimestamp() % 1000000;
            $rows[] = ['server_id' => $server->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'cpu_percent' => $spike && $at->gte($spike) ? 97 : $cpu + $at->minute % 3, 'memory_total_bytes' => 1000, 'memory_used_bytes' => 400, 'uptime_seconds' => (int) $uptime];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }
    }

    private function websiteIncident(string $team = 'a', int $resolvedAfter = 40): Incident
    {
        $domain = $this->{$team}['domain'];
        $incident = app(IncidentManager::class)->trigger(new Signal("http:{$team}", 'http', 'high', "{$domain->name} onbereikbaar", null, $domain, startedAt: $this->onset->toMutable(), teamId: $this->{$team}['team']->id));
        $incident->forceFill(['opened_at' => $this->onset->addMinutes(2), 'status' => 'resolved', 'resolved_at' => $this->onset->addMinutes($resolvedAfter), 'active_fingerprint' => null])->save();

        foreach ([[5, false], [10, false], [45, true]] as [$minute, $success]) {
            $domain->checks()->create(['type' => 'http', 'result' => $success ? 'up' : 'down', 'success' => $success, 'message' => $success ? null : 'Connection refused', 'checked_at' => $this->onset->addMinutes($minute)]);
        }

        return $incident->fresh();
    }

    private function serviceStopped(string $team, int $minutesBefore, ?int $recoveredAfterOnset = 35): void
    {
        $rows = [['service_check_id' => $this->{$team}['service']->id, 'status' => 'stopped', 'previous_status' => 'running', 'detail' => 'inactive/dead', 'recorded_at' => $this->onset->subMinutes($minutesBefore)]];

        if ($recoveredAfterOnset !== null) {
            $rows[] = ['service_check_id' => $this->{$team}['service']->id, 'status' => 'running', 'previous_status' => 'stopped', 'detail' => 'active/running', 'recorded_at' => $this->onset->addMinutes($recoveredAfterOnset)];
        }

        DB::table('service_check_events')->insert($rows);
    }

    private function investigate(Incident $incident): array
    {
        return app(InvestigationRunner::class)->investigate($incident, CarbonImmutable::now());
    }

    private function hypothesis(array $result, string $code): ?array
    {
        return collect($result['hypotheses'])->firstWhere('code', $code);
    }

    public function test_a_service_that_stopped_before_the_onset_is_the_leading_hypothesis_with_its_evidence(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        $this->serviceStopped('a', 20);
        $result = $this->investigate($this->websiteIncident());

        $primary = collect($result['hypotheses'])->firstWhere('key', $result['primary']);
        $this->assertSame('service_failed', $primary['code']);
        $this->assertSame('service:'.$this->a['service']->id, $primary['resource']);
        // Structural only (nginx runs on the server the website depends on): medium, not high.
        $this->assertSame('medium', $primary['confidence']);
        $this->assertContains('structural_only', $primary['reasons']);
        $this->assertNotEmpty($primary['supporting']);

        $evidence = collect($result['evidence'])->keyBy('key');
        $support = $evidence[$primary['supporting'][0]];
        $this->assertSame('service', $support['code']);
        $this->assertSame(['type' => 'service_check_event', 'id' => DB::table('service_check_events')->min('id')], $support['source']);
        $this->assertSame('before', $support['phase']);

        // The path runs from the website through the server to the service.
        $this->assertSame(['domain:'.$this->a['domain']->id, 'server:'.$this->a['server']->id, 'service:'.$this->a['service']->id], $primary['path']);
        $this->assertNotEmpty($primary['next_steps']);

        // Nothing depends on the service record itself: impact is analysed from its server.
        $this->assertSame('server:'.$this->a['server']->id, $result['impact']['cause']);
        $this->assertSame('service:'.$this->a['service']->id, $result['impact']['via']);
        $this->assertContains('domain:'.$this->a['domain']->id, array_column($result['impact']['items'], 'key'));
        // Stopped before the onset and recovered during it: failing, not healthy.
        $this->assertSame('failing', collect($result['impact']['items'])->firstWhere('key', 'service:'.$this->a['service']->id)['assessment']);

        // Insufficient evidence is always listed, after a medium or better leader.
        $this->assertNotNull($this->hypothesis($result, 'insufficient_evidence'));
    }

    public function test_a_confirmed_dependency_on_the_service_itself_makes_it_high(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $this->a['domain']->id, 'dependency_type' => 'service', 'dependency_id' => $this->a['service']->id]);
        $this->serviceStopped('a', 20);

        $result = $this->investigate($this->websiteIncident());

        $this->assertSame('high', $this->hypothesis($result, 'service_failed')['confidence']);
        $this->assertContains('confirmed_path', $this->hypothesis($result, 'service_failed')['reasons']);
    }

    public function test_a_cause_that_starts_after_the_onset_or_recovered_before_it_is_rejected(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        // Stopped 5 minutes after the website failed.
        DB::table('service_check_events')->insert(['service_check_id' => $this->a['service']->id, 'status' => 'stopped', 'previous_status' => 'running', 'recorded_at' => $this->onset->addMinutes(5)]);
        // An upstream server incident that was over before the onset.
        $other = app(IncidentManager::class)->trigger(new Signal('agent:early', 'agent', 'critical', 'alpha-server offline', null, $this->a['server'], teamId: $this->a['team']->id));
        $other->forceFill(['opened_at' => $this->onset->subMinutes(90), 'started_at' => $this->onset->subMinutes(90), 'status' => 'resolved', 'resolved_at' => $this->onset->subMinutes(60), 'active_fingerprint' => null])->save();

        $result = $this->investigate($this->websiteIncident());

        $this->assertNull($this->hypothesis($result, 'service_failed'));
        $this->assertNull($this->hypothesis($result, 'upstream_incident'));
        $reasons = collect($result['rejected'])->mapWithKeys(fn ($r) => [$r['code'] => $r['reason']])->all();
        $this->assertSame('after_onset', $reasons['service_failed']);
        $this->assertSame('recovered_before_onset', $reasons['upstream_incident']);
    }

    public function test_an_unrelated_incident_at_the_same_time_without_a_dependency_is_not_a_cause(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        $otherDomain = Domain::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-other.example.com']);
        app(IncidentManager::class)->trigger(new Signal('http:unrelated', 'http', 'critical', 'alpha-other down', null, $otherDomain, startedAt: $this->onset->subMinutes(5)->toMutable(), teamId: $this->a['team']->id));

        $result = $this->investigate($this->websiteIncident());

        $this->assertNull($this->hypothesis($result, 'upstream_incident'));
        $this->assertStringNotContainsString('alpha-other', json_encode($result['hypotheses']));
        $this->assertSame('insufficient_evidence', collect($result['hypotheses'])->firstWhere('key', $result['primary'])['code']);
    }

    public function test_an_upstream_agent_incident_while_other_websites_answered_is_contradicted(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 09:58:00');
        $other = app(IncidentManager::class)->trigger(new Signal('agent:alpha', 'agent', 'critical', 'alpha-server offline', null, $this->a['server'], startedAt: $this->onset->subMinutes(3)->toMutable(), teamId: $this->a['team']->id));
        $other->forceFill(['opened_at' => $this->onset->subMinutes(2)])->save();
        // A second website on the same server kept answering.
        $second = Domain::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-second.example.com']);
        ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $second->id, 'dependency_type' => 'server', 'dependency_id' => $this->a['server']->id]);

        foreach ([5, 15, 25] as $minute) {
            $second->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => $this->onset->addMinutes($minute)]);
        }

        $result = $this->investigate($this->websiteIncident());

        $upstream = $this->hypothesis($result, 'upstream_incident');
        $this->assertNotNull($upstream);
        $this->assertNotEmpty($upstream['contradicting']);
        $this->assertSame('medium', $upstream['confidence'], 'high minus one level for the contradiction');
        $this->assertContains('contradicted', $upstream['reasons']);

        // The agent rule, like ServerDiagnosis: the host answered, so the agent is the problem.
        $this->assertSame('agent_unhealthy', $this->hypothesis($result, 'agent_unhealthy')['code']);
        $this->assertNull($this->hypothesis($result, 'host_unreachable'));
    }

    public function test_a_silent_agent_without_independent_checks_is_never_called_an_unreachable_host(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 09:58:00');
        // The default website's checks belong to the subject; drop them so nothing independent remains.
        $this->a['domain']->checks()->delete();
        $incident = app(IncidentManager::class)->trigger(new Signal('agent:alpha', 'agent', 'critical', 'alpha-server offline', null, $this->a['server'], startedAt: $this->onset->toMutable(), teamId: $this->a['team']->id));

        $result = $this->investigate($incident->fresh());

        $this->assertNull($this->hypothesis($result, 'host_unreachable'));
        $unknown = $this->hypothesis($result, 'agent_offline_unknown');
        $this->assertSame('insufficient', $unknown['confidence']);
        $this->assertContains('independent_checks', $unknown['missing']);
        $this->assertNotEmpty(collect($result['evidence'])->where('code', 'agent_silent')->all());
    }

    public function test_missing_metrics_are_reported_as_a_gap_and_never_invented(): void
    {
        MetricSample::query()->delete();
        $result = $this->investigate($this->websiteIncident());

        $this->assertContains(['code' => 'no_metrics', 'params' => ['resource' => 'server:'.$this->a['server']->id]], $result['gaps']);
        $this->assertSame([], collect($result['evidence'])->whereIn('code', ['metric_shift', 'metric_high'])->all());
        $coverage = collect($result['evidence'])->firstWhere('code', 'agent_silent');
        $this->assertSame(0, $coverage['params']['samples_during']);
        $this->assertNull($coverage['params']['last_before']);
    }

    public function test_resource_pressure_is_at_most_medium_and_marked_as_correlation(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00', spikeFrom: '2026-11-10 08:50:00');
        $result = $this->investigate($this->websiteIncident());

        $cpu = $this->hypothesis($result, 'cpu_saturation');
        $this->assertNotNull($cpu);
        $this->assertSame('medium', $cpu['confidence'], 'threshold exceeded, but never high');
        $this->assertContains('correlation_only', $cpu['reasons']);
    }

    public function test_a_reboot_shortly_before_the_onset_is_detected_from_uptime(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00', rebootAt: '2026-11-10 09:55:00');
        $result = $this->investigate($this->websiteIncident());

        $this->assertSame('medium', $this->hypothesis($result, 'reboot')['confidence']);
    }

    public function test_maintenance_of_the_server_during_the_onset_is_a_hypothesis(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        $this->a['server']->forceFill(['maintenance_starts_at' => $this->onset->subMinutes(10), 'maintenance_ends_at' => $this->onset->addMinutes(30)])->save();

        $result = $this->investigate($this->websiteIncident());

        $this->assertSame('planned_maintenance', collect($result['hypotheses'])->firstWhere('key', $result['primary'])['code']);
    }

    public function test_a_collector_failure_makes_the_run_partial_and_caps_confidence(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        $this->serviceStopped('a', 20);
        ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $this->a['domain']->id, 'dependency_type' => 'service', 'dependency_id' => $this->a['service']->id]);
        $this->app->bind(ServiceCollector::class, fn () => new class implements Collector
        {
            public function name(): string
            {
                return 'services';
            }

            public function collect(EvidenceScope $scope): array
            {
                throw new \RuntimeException('database password=hunter2 unreachable');
            }
        });

        $incident = $this->websiteIncident();
        $run = app(Investigations::class)->request($incident, 'manual', $this->a['admin']);
        $run = $run->fresh();

        $this->assertSame('partial', $run->status);
        $this->assertSame('failed', $run->result['collectors']['services']);
        $this->assertContains(['code' => 'collector_failed', 'params' => ['collector' => 'services']], $run->result['gaps']);
        $this->assertNull(collect($run->result['hypotheses'])->firstWhere('code', 'service_failed'), 'no service evidence, no service hypothesis');
        $this->assertStringNotContainsString('hunter2', json_encode($run->result));
        $this->assertTrue($run->summary['partial']);
    }

    public function test_impact_lists_dependents_with_their_paths_and_observed_state(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 09:58:00');
        $agent = app(IncidentManager::class)->trigger(new Signal('agent:alpha', 'agent', 'critical', 'alpha-server offline', null, $this->a['server'], startedAt: $this->onset->toMutable(), teamId: $this->a['team']->id));
        $second = Domain::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-second.example.com']);
        $third = Domain::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'alpha-third.example.com']);

        foreach ([$second, $third] as $domain) {
            ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $domain->id, 'dependency_type' => 'server', 'dependency_id' => $this->a['server']->id]);
        }

        $second->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'checked_at' => $this->onset->addMinutes(5)]);
        $this->a['domain']->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'checked_at' => $this->onset->addMinutes(6)]);
        // An inconsistent row pointing into the other team is never followed.
        ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $this->b['domain']->id, 'dependency_type' => 'server', 'dependency_id' => $this->a['server']->id]);

        $result = $this->investigate($agent->fresh());

        $this->assertSame('host_unreachable', collect($result['hypotheses'])->firstWhere('key', $result['primary'])['code']);
        $items = collect($result['impact']['items'])->keyBy('key');
        $this->assertSame('failing', $items['domain:'.$second->id]['assessment']);
        $this->assertSame('unobserved', $items['domain:'.$third->id]['assessment']);
        $this->assertSame(['server:'.$this->a['server']->id, 'domain:'.$third->id], $items['domain:'.$third->id]['path']);
        $this->assertSame(['confirmed'], $items['domain:'.$third->id]['edges']);
        $this->assertSame('structural', $items['service:'.$this->a['service']->id]['edges'][0]);
        $this->assertArrayNotHasKey('domain:'.$this->b['domain']->id, $items->all());
        $this->assertStringNotContainsString('bravo', json_encode($result));
    }

    public function test_the_same_data_and_time_give_the_same_digest_and_runs_are_kept(): void
    {
        $this->samples('a', '2026-11-09 08:00:00', '2026-11-10 12:00:00');
        $this->serviceStopped('a', 20);
        $incident = $this->websiteIncident();

        $first = app(Investigations::class)->request($incident, 'manual', $this->a['admin'])->fresh();
        $this->travel(2)->minutes();
        $second = app(Investigations::class)->request($incident, 'manual', $this->a['admin'])->fresh();

        $this->assertSame('completed', $second->status);
        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame($first->result, $first->fresh()->result, 'a completed snapshot is never rewritten');

        // The window runs until 2 hours after recovery (or now), so a later
        // run may see more; the same as_of on the same data gives the same digest.
        $again = app(InvestigationRunner::class)->investigate($incident, CarbonImmutable::instance($second->as_of));
        $this->assertSame($second->input_digest, InvestigationRunner::digest($again));
        $this->assertSame(2, Investigation::query()->withoutGlobalScopes()->where('incident_id', $incident->id)->count());
    }
}
