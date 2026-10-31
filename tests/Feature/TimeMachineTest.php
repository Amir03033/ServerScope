<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\ResourceDependency;
use App\Services\Analytics\MetricRollups;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Incidents\TimeMachine;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class TimeMachineTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private CarbonImmutable $onset;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app()->setLocale('en');
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

    /** Minute samples for the alpha server in [$from, $to); CPU 10 %, or 95 % from $spikeFrom. */
    private function samples(string $from, string $to, ?string $spikeFrom = null): void
    {
        // Replace the single sample the TwoTeams fixture stores.
        MetricSample::query()->where('server_id', $this->a['server']->id)->delete();
        $rows = [];
        $spike = $spikeFrom ? CarbonImmutable::parse($spikeFrom, 'UTC') : null;

        for ($at = CarbonImmutable::parse($from, 'UTC'); $at->lt(CarbonImmutable::parse($to, 'UTC')); $at = $at->addMinute()) {
            $rows[] = ['server_id' => $this->a['server']->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'cpu_percent' => $spike && $at->gte($spike) ? 95 : 10 + $at->minute % 3, 'memory_total_bytes' => 1000, 'memory_used_bytes' => 400];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }
    }

    /** An HTTP incident on the alpha website (which runs on the alpha server), resolved at 10:40. */
    private function websiteIncident(): Incident
    {
        $domain = $this->a['domain'];
        $service = $this->a['service'];

        $incident = app(IncidentManager::class)->trigger(new Signal('http:tm', 'http', 'high', 'alpha-shop.example.com onbereikbaar', null, $domain, startedAt: $this->onset->toMutable(), teamId: $this->a['team']->id));
        $incident->forceFill(['opened_at' => $this->onset->addMinutes(2), 'status' => 'resolved', 'resolved_at' => $this->onset->addMinutes(40)])->save();

        // Before: DNS change, nginx stopped; during: HTTP failing; after: recovered.
        DB::table('dns_changes')->insert(['domain_id' => $domain->id, 'record_type' => 'MX', 'previous' => '[]', 'current' => '[]', 'added' => '[]', 'removed' => '[]', 'detected_at' => $this->onset->subMinutes(90)]);
        DB::table('service_check_events')->insert([
            ['service_check_id' => $service->id, 'status' => 'stopped', 'previous_status' => 'running', 'detail' => 'inactive/dead', 'recorded_at' => $this->onset->subMinutes(20)],
            ['service_check_id' => $service->id, 'status' => 'running', 'previous_status' => 'stopped', 'detail' => 'active/running', 'recorded_at' => $this->onset->addMinutes(35)],
        ]);

        foreach ([[1, true], [5, false], [10, false], [41, false], [45, true]] as [$minute, $success]) {
            $domain->checks()->create(['type' => 'http', 'result' => $success ? 'up' : 'down', 'success' => $success, 'message' => $success ? null : 'Connection refused', 'checked_at' => $this->onset->addMinutes($minute)]);
        }

        return $incident->fresh();
    }

    public function test_the_timeline_is_chronological_with_phases_and_includes_dependencies(): void
    {
        $tm = app(TimeMachine::class)->build($this->websiteIncident());

        $times = array_map(fn ($e) => $e['at']->getTimestamp(), $tm['events']);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'events are in chronological order');

        $titles = array_map(fn ($e) => [$e['phase'], $e['category'], $e['title']], $tm['events']);
        $this->assertContains(['before', 'change', 'DNS MX records changed'], $titles);
        $this->assertContains(['before', 'service', 'Service running → stopped'], $titles);
        $this->assertContains(['during', 'incident', 'Problem started'], $titles);
        $this->assertContains(['during', 'check', 'HTTP check failing'], $titles);
        $this->assertContains(['after', 'recovery', 'HTTP check succeeding again'], $titles);
        $this->assertContains(['during', 'recovery', 'Service stopped → running'], $titles);

        // Only flips are shown: three failed checks in a row are one event.
        $this->assertCount(1, array_filter($titles, fn ($t) => $t[2] === 'HTTP check failing'));
        // The DNS change happened before the service stopped.
        $order = array_column($titles, 2);
        $this->assertLessThan(array_search('Service running → stopped', $order), array_search('DNS MX records changed', $order));

        // The server is in scope through the confirmed dependency, with its service.
        $this->assertSame(['subject', 'upstream', 'service'], array_values(array_map(fn ($r) => $r['relation'], $tm['resources'])));
    }

    public function test_evidence_and_possible_explanations_are_kept_apart(): void
    {
        $this->samples('2026-11-09 08:00:00', '2026-11-10 12:00:00', spikeFrom: '2026-11-10 09:00:00');

        $incident = $this->websiteIncident();
        $tm = app(TimeMachine::class)->build($incident);
        $findings = array_column($tm['findings'], 'text');

        $this->assertContains('cpu_percent on alpha-server averaged 95% in the hour before the onset, against a median of 11% over the previous 24 hours.', $findings);
        $this->assertContains('90 min before the onset — alpha-shop.example.com: DNS MX records changed', $findings);
        $this->assertNotEmpty(array_filter($findings, fn ($f) => str_starts_with($f, '20 min before the onset — alpha-nginx') && str_ends_with($f, 'Service running → stopped')));

        // Explanations are hedged, ordered by directness, and each names its evidence.
        $this->assertNotEmpty($tm['explanations']);
        $this->assertStringContainsString('may', $tm['explanations'][0]['text']);
        $this->assertStringContainsString('alpha-nginx', $tm['explanations'][0]['text']);
        foreach ($tm['explanations'] as $explanation) {
            $this->assertMatchesRegularExpression('/\bmay\b/', $explanation['text']);
            $this->assertContains($explanation['basis'], $findings);
        }

        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $incident))
            ->assertOk()
            ->assertSee(trans('time_machine.caveat', [], 'nl'))
            ->assertSee(trans('time_machine.evidence_title', [], 'nl'))
            ->assertSee(trans('time_machine.explanations_title', [], 'nl'))
            ->assertSee('alpha-nginx');
    }

    public function test_history_uses_rollups_for_older_hours_and_raw_samples_for_recent_ones(): void
    {
        $this->samples('2026-11-09 08:00:00', '2026-11-10 12:00:00');
        app(MetricRollups::class)->rollup($this->a['server']);
        $this->assertNotNull($this->a['server']->fresh()->metrics_rolled_up_until);

        // Retention has removed raw samples older than 09:30.
        MetricSample::query()->where('collected_at', '<', '2026-11-10 09:30:00')->delete();

        $incident = $this->websiteIncident();
        $metrics = app(TimeMachine::class)->build($incident)['metrics'][0];
        $cpu = $metrics['hourly']['cpu_percent'];

        // 24 hours before the onset (10:00 UTC) up to now (12:00): 26 hourly
        // points, labelled in the display timezone (Europe/Amsterdam).
        $this->assertCount(26, $cpu);
        $this->assertSame('09-11 11:00', $cpu[0]['label']);
        // Hours whose raw samples are gone still have values (rollups).
        $this->assertEqualsWithDelta(11.0, $cpu[0]['value'], 0.01);
        $this->assertNotNull($cpu[22]['value']);
        // The last hour is not aggregated yet: raw samples.
        $this->assertNotNull($cpu[25]['value']);
        // The incident period is marked.
        $this->assertFalse($cpu[22]['flag']);
        $this->assertTrue($cpu[24]['flag']);

        // Raw detail around the onset (09:30 onwards is still kept).
        $this->assertNotEmpty($metrics['detail']['cpu_percent']);
        $this->assertSame('10:30', $metrics['detail']['cpu_percent'][0]['label']); // 09:30 UTC
    }

    public function test_missing_data_is_reported_instead_of_failing(): void
    {
        // Nothing stored for this server around the incident.
        MetricSample::query()->delete();
        $incident = $this->websiteIncident();
        $tm = app(TimeMachine::class)->build($incident);

        $this->assertContains('No metrics of alpha-server are available for this period (not reported, or removed by retention).', $tm['gaps']);
        $this->assertSame([], $tm['metrics'][0]['detail']);

        // A manual incident without a monitored resource.
        $manual = app(IncidentManager::class)->trigger(new Signal('manual:tm', 'manual', 'warning', 'Handmatig', null, null, teamId: $this->a['team']->id));
        $tm = app(TimeMachine::class)->build($manual);
        $this->assertSame([], $tm['resources']);
        $this->assertSame([], $tm['metrics']);
        $this->assertContains(__('time_machine.gaps.no_subject'), $tm['gaps']);
        $this->assertSame([], $tm['explanations']);

        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $manual))->assertOk()->assertSee(trans('time_machine.gaps.no_subject', [], 'nl'));
    }

    public function test_access_follows_incident_access_and_never_crosses_teams(): void
    {
        $incident = $this->websiteIncident();

        $this->get(route('incidents.time-machine', $incident))->assertRedirect(route('login'));

        // Any member of the team (not only admins), linked from the incident.
        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()->assertSee(route('incidents.time-machine', $incident));
        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $incident))->assertOk()->assertDontSee('bravo');

        // Another team's incident does not exist for this user.
        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $this->b['incident']))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('incidents.time-machine', $incident))->assertNotFound();
    }

    public function test_a_dependency_row_pointing_into_another_team_is_ignored(): void
    {
        // An inconsistent row: alpha's website "depends on" bravo's server.
        ResourceDependency::forceCreate(['team_id' => $this->a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $this->a['domain']->id, 'dependency_type' => 'server', 'dependency_id' => $this->b['server']->id]);
        DB::table('service_check_events')->insert(['service_check_id' => $this->b['service']->id, 'status' => 'failed', 'previous_status' => 'running', 'detail' => 'bravo detail', 'recorded_at' => $this->onset->subMinutes(5)]);
        $incident = $this->websiteIncident();

        $tm = Tenant::run($this->a['team']->id, fn () => app(TimeMachine::class)->build($incident));

        $this->assertNotContains('server:'.$this->b['server']->id, array_keys($tm['resources']));
        $this->assertNotContains('service:'.$this->b['service']->id, array_keys($tm['resources']));
        $this->assertStringNotContainsString('bravo', json_encode(array_column($tm['events'], 'resource')).json_encode(array_column($tm['events'], 'detail')));

        // Same result in the web request.
        $this->actingAs($this->a['admin'])->get(route('incidents.time-machine', $incident))->assertOk()->assertDontSee('bravo');
    }
}
