<?php

namespace Tests\Feature\Intelligence;

use App\Jobs\RunInvestigation;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\MetricSample;
use App\Services\Incidents\Evidence\Collectors;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Intelligence\ImpactAnalyzer;
use App\Services\Intelligence\IntegrityViolation;
use App\Services\Intelligence\InvestigationRunner;
use App\Services\Intelligence\Investigations;
use App\Services\Intelligence\NextStepAdvisor;
use App\Services\Intelligence\TeamIntegrityGuard;
use App\Services\Nexus\TopologyBuilder;
use App\Tenancy\Tenancy;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Tenant isolation, authorization, lifecycle triggers, states and
 * rendering of NEXUS Intelligence investigations.
 */
class InvestigationAccessTest extends TestCase
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

    /** Both teams get the same incident at the same moment, with the same kind of evidence. */
    private function incidents(): array
    {
        $out = [];

        foreach (['a', 'b'] as $team) {
            $t = $this->{$team};
            $domain = $t['domain'];
            $incident = app(IncidentManager::class)->trigger(new Signal("http:{$team}", 'http', 'high', "{$domain->name} onbereikbaar", null, $domain, startedAt: $this->onset->toMutable(), teamId: $t['team']->id));
            $domain->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'message' => "{$domain->name} <script>alert(1)</script> refused", 'checked_at' => $this->onset->addMinutes(3)]);
            DB::table('service_check_events')->insert(['service_check_id' => $t['service']->id, 'status' => 'stopped', 'previous_status' => 'running', 'detail' => "{$t['team']->slug} detail", 'recorded_at' => $this->onset->subMinutes(10)]);
            MetricSample::query()->create(['server_id' => $t['server']->id, 'cpu_percent' => 20, 'memory_total_bytes' => 100, 'memory_used_bytes' => 10, 'collected_at' => $this->onset->subMinutes(20)]);
            $out[$team] = $incident->fresh();
        }

        return $out;
    }

    private function investigateNow(Incident $incident): Investigation
    {
        $run = app(Investigations::class)->request($incident, 'manual', null);

        return $run->fresh();
    }

    public function test_two_teams_with_identical_timestamps_never_see_each_others_data(): void
    {
        ['a' => $a, 'b' => $b] = $this->incidents();

        $run = $this->investigateNow($a);
        $json = json_encode($run->result);

        $this->assertSame('completed', $run->status);
        $this->assertSame($this->a['team']->id, $run->team_id);
        $this->assertStringNotContainsString('bravo', $json);
        $this->assertStringContainsString('alpha', $json);

        // Every referenced resource belongs to team A.
        foreach ($run->result['evidence'] as $item) {
            if ($item['resource']) {
                [$type, $id] = explode(':', $item['resource']);
                $this->assertSame($this->a['team']->id, Tenancy::teamIdOfMorph($type, $id), $item['resource']);
            }
        }

        $this->assertNotContains('server:'.$this->b['server']->id, array_column($run->result['resources'], 'key'));
    }

    public function test_the_job_runs_without_a_request_tenant(): void
    {
        ['a' => $a] = $this->incidents();
        Tenant::set($this->b['team']->id);
        $run = Investigation::query()->withoutGlobalScopes()->forceCreate(['team_id' => $a->team_id, 'incident_id' => $a->id, 'status' => 'queued', 'trigger' => 'manual', 'pipeline_version' => 1]);
        Tenant::forget();

        (new RunInvestigation($run->id))->handle(app(InvestigationRunner::class));

        $run = $run->fresh();
        $this->assertSame('completed', $run->status);
        $this->assertStringNotContainsString('bravo', json_encode($run->result));
        $this->assertNull(Tenant::id());
    }

    public function test_another_team_cannot_see_rerun_or_export_an_investigation(): void
    {
        ['a' => $a] = $this->incidents();
        $run = $this->investigateNow($a);

        $this->get(route('investigations.show', $run))->assertRedirect(route('login'));

        $this->actingAs($this->b['admin'])->get(route('investigations.show', $run))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('investigations.export', $run))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('incidents.investigation', $a))->assertNotFound();
        $this->actingAs($this->b['admin'])->post(route('investigations.store', $a))->assertNotFound();
        // Changing the id to another team's run from inside team A.
        $bRun = $this->investigateNow($this->b['incident']);
        $this->actingAs($this->a['admin'])->get(route('investigations.show', $bRun))->assertNotFound();
        $this->actingAs($this->a['admin'])->get(route('investigations.export', $bRun))->assertNotFound();

        $this->assertSame(1, Investigation::query()->withoutGlobalScopes()->where('incident_id', $a->id)->count());
    }

    public function test_members_view_and_only_admins_rerun_which_is_audited(): void
    {
        ['a' => $a] = $this->incidents();

        $this->actingAs($this->a['member'])->get(route('incidents.investigation', $a))->assertOk()->assertSee(__('investigations.states.none'));
        $this->actingAs($this->a['member'])->post(route('investigations.store', $a))->assertForbidden();
        $this->assertSame(0, Investigation::query()->withoutGlobalScopes()->count());

        $response = $this->actingAs($this->a['admin'])->post(route('investigations.store', $a));
        $run = Investigation::query()->withoutGlobalScopes()->sole();
        $response->assertRedirect(route('investigations.show', $run));

        $this->assertSame('manual', $run->trigger);
        $this->assertSame($this->a['admin']->id, $run->requested_by);
        $this->assertSame('completed', $run->status);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('team_id', $this->a['team']->id)->where('action', 'incident.investigation_requested')->exists());

        // Cooldown: a second manual request right away is refused.
        $this->actingAs($this->a['admin'])->post(route('investigations.store', $a))->assertSessionHas('error', __('investigations.refused.cooldown'));
    }

    public function test_the_page_renders_the_result_with_untrusted_text_escaped(): void
    {
        ['a' => $a] = $this->incidents();
        $run = $this->investigateNow($a);

        $this->actingAs($this->a['member'])->get(route('investigations.show', $run))
            ->assertOk()
            ->assertSee(__('investigations.caveat'))
            ->assertSee('data-testid="investigation-primary"', false)
            ->assertSee('data-testid="investigation-impact"', false)
            ->assertSee('data-testid="investigation-evidence"', false)
            ->assertSee('data-testid="investigation-history"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('bravo');

        // The incident page shows one root cause section (rules and AI layer), linking to the full run.
        $this->actingAs($this->a['member'])->get(route('incidents.show', $a))
            ->assertOk()
            ->assertSee('data-testid="root-cause"', false)
            ->assertSee('data-testid="root-cause-ai"', false)
            ->assertDontSee('data-testid="ai-analysis"', false)
            ->assertSee(route('investigations.show', $run));

        // Evidence filter.
        $this->actingAs($this->a['member'])->get(route('investigations.show', [$run, 'category' => 'service']))->assertOk()->assertSee('alpha detail')
            ->assertDontSee('data-key="'.collect($run->result['evidence'])->firstWhere('code', 'check_failed')['key'].'"', false);
        $this->actingAs($this->a['member'])->get(route('investigations.show', [$run, 'category' => 'nonsense']))->assertSessionHasErrors('category');
    }

    public function test_every_state_renders_in_both_languages(): void
    {
        ['a' => $a] = $this->incidents();
        $done = $this->investigateNow($a);

        foreach (['queued', 'running', 'failed', 'partial', 'superseded', 'completed'] as $status) {
            $run = Investigation::query()->withoutGlobalScopes()->forceCreate([
                'team_id' => $a->team_id, 'incident_id' => $a->id, 'status' => $status, 'trigger' => 'resolved', 'pipeline_version' => 1,
                'error_code' => $status === 'failed' ? 'integrity_violation' : null,
                ...(in_array($status, ['partial', 'superseded', 'completed'], true) ? ['result' => $done->result, 'summary' => $done->summary, 'as_of' => $done->as_of, 'finished_at' => now()] : []),
            ]);

            foreach (['en', 'nl'] as $locale) {
                $this->a['member']->forceFill(['locale' => $locale])->save();
                $response = $this->actingAs($this->a['member']->fresh())->get(route('investigations.show', $run))->assertOk()->assertSee('data-status="'.$status.'"', false);
                $response->assertSee(trans('investigations.status.'.$status, [], $locale));

                if ($status === 'failed') {
                    $response->assertSee(trans('investigations.errors.integrity_violation', [], $locale));
                }

                if ($status === 'partial') {
                    $response->assertSee(trans('investigations.states.partial', [], $locale));
                }
            }
        }
    }

    public function test_every_code_the_engine_can_emit_has_a_translation(): void
    {
        $codes = [
            'hypotheses' => array_keys(trans('investigations.hypotheses', [], 'en')),
            'steps' => [...array_merge(...array_values(NextStepAdvisor::STEPS)), 'check_agent_reporting'],
        ];

        foreach (['en', 'nl'] as $locale) {
            foreach (array_keys(NextStepAdvisor::STEPS) as $code) {
                $this->assertTrue(Lang::hasForLocale("investigations.hypotheses.{$code}.title", $locale), "{$locale}: hypothesis {$code}");
                $this->assertTrue(Lang::hasForLocale("investigations.hypotheses.{$code}.text", $locale), "{$locale}: hypothesis {$code}");
            }

            foreach ($codes['steps'] as $code) {
                $this->assertTrue(Lang::hasForLocale("investigations.steps.{$code}", $locale), "{$locale}: step {$code}");
            }

            foreach (Collectors::ORDER as $class) {
                $this->assertTrue(Lang::hasForLocale('investigations.collectors.'.app($class)->name(), $locale));
            }
        }

        // Every rule, reason and missing code used in the source is translated.
        $reasons = array_keys(trans('investigations.reasons', [], 'en'));
        $missing = array_keys(trans('investigations.missing', [], 'en'));

        foreach (['precedes_onset', 'contradicted', 'data_gap', 'collector_failed', 'truncated', 'correlation_only', 'structural_only', 'independent_checks'] as $code) {
            $this->assertContains($code, $reasons);
        }

        $this->assertSame(array_keys(trans('investigations.reasons', [], 'en')), array_keys(trans('investigations.reasons', [], 'nl')));
        $this->assertSame($missing, array_keys(trans('investigations.missing', [], 'nl')));
    }

    public function test_the_integrity_guard_rejects_a_result_that_refers_to_another_team(): void
    {
        ['a' => $a] = $this->incidents();
        $result = app(InvestigationRunner::class)->investigate($a, CarbonImmutable::now());

        app(TeamIntegrityGuard::class)->assert($this->a['team']->id, $result);

        $corrupt = $result;
        $corrupt['impact']['items'][] = ['key' => 'server:'.$this->b['server']->id, 'path' => [], 'observed' => ['incidents' => []]];
        $this->expectException(IntegrityViolation::class);
        app(TeamIntegrityGuard::class)->assert($this->a['team']->id, $corrupt);
    }

    public function test_a_corrupted_result_fails_the_run_instead_of_being_stored(): void
    {
        ['a' => $a] = $this->incidents();
        $bServer = 'server:'.$this->b['server']->id;
        $this->app->instance(ImpactAnalyzer::class, new class($bServer) extends ImpactAnalyzer
        {
            public function __construct(private string $leak) {}

            public function analyze(int $teamId, ?string $cause, CarbonImmutable $from, CarbonImmutable $to, int $depth, int $limit): array
            {
                return ['cause' => $cause, 'items' => [['key' => $this->leak, 'type' => 'server', 'id' => 1, 'label' => 'x', 'depth' => 1, 'path' => [$this->leak], 'edges' => [], 'assessment' => 'unobserved', 'observed' => ['ok' => 0, 'failed' => 0, 'incidents' => []]]], 'truncated' => false];
            }

            public function websitesOn(int $teamId, string $server, CarbonImmutable $from, CarbonImmutable $to, array $exclude, int $limit): array
            {
                return [];
            }
        });

        $run = $this->investigateNow($a);

        $this->assertSame('failed', $run->status);
        $this->assertSame('integrity_violation', $run->error_code);
        $this->assertNull($run->result);
    }

    public function test_lifecycle_events_queue_one_run_for_the_root_and_respect_limits(): void
    {
        config(['serverscope.intelligence.auto_triggers' => ['opened', 'reopened', 'resolved']]);
        Queue::fake();

        $incident = app(IncidentManager::class)->trigger(new Signal('http:auto', 'http', 'high', 'alpha down', null, $this->a['domain'], teamId: $this->a['team']->id));
        // A repeated failure of the same problem is no new event.
        app(IncidentManager::class)->trigger(new Signal('http:auto', 'http', 'high', 'alpha down', null, $this->a['domain'], teamId: $this->a['team']->id));

        Queue::assertPushed(RunInvestigation::class, 1);
        $run = Investigation::query()->withoutGlobalScopes()->sole();
        $this->assertSame('opened', $run->trigger);
        $this->assertNull($run->requested_by);

        // Recovery while the first run is still queued: no second run.
        app(IncidentManager::class)->recover('http:auto', 'ok');
        Queue::assertPushed(RunInvestigation::class, 1);

        // A grouped child is investigated through its root.
        $child = app(IncidentManager::class)->trigger(new Signal('page:auto', 'page', 'high', 'alpha page down', null, $this->a['page'], teamId: $this->a['team']->id));
        $child->forceFill(['parent_id' => $incident->id])->save();
        $this->assertSame($run->id, app(Investigations::class)->request($child->fresh(), 'opened')->id);

        // The hourly cap per team.
        config(['serverscope.intelligence.max_runs_per_team_per_hour' => 2]);
        $other = app(IncidentManager::class)->trigger(new Signal('http:other', 'http', 'high', 'alpha other', null, $this->a['server'], teamId: $this->a['team']->id));
        $this->assertSame('limit', app(Investigations::class)->request($other, 'opened'));

        // Manual incidents without a resource are not investigated automatically.
        app(IncidentManager::class)->trigger(new Signal('manual:x', 'manual', 'warning', 'x', null, null, teamId: $this->a['team']->id));
        $this->assertSame(0, Investigation::query()->withoutGlobalScopes()->whereHas('incident', fn ($q) => $q->withoutGlobalScopes()->where('source', 'manual'))->count());
    }

    public function test_a_duplicate_job_does_nothing_and_a_stuck_run_is_taken_over(): void
    {
        ['a' => $a] = $this->incidents();
        $run = $this->investigateNow($a);
        $finished = $run->finished_at;

        app(InvestigationRunner::class)->run($run->id);
        $this->assertEquals($finished, $run->fresh()->finished_at, 'a finished run is not run again');

        $stuck = Investigation::query()->withoutGlobalScopes()->forceCreate(['team_id' => $a->team_id, 'incident_id' => $a->id, 'status' => 'running', 'trigger' => 'manual', 'pipeline_version' => 1, 'started_at' => now()->subMinutes(10)]);
        app(InvestigationRunner::class)->run($stuck->id);
        $this->assertSame('completed', $stuck->fresh()->status);

        $failedJob = Investigation::query()->withoutGlobalScopes()->forceCreate(['team_id' => $a->team_id, 'incident_id' => $a->id, 'status' => 'running', 'trigger' => 'manual', 'pipeline_version' => 1, 'started_at' => now()]);
        (new RunInvestigation($failedJob->id))->failed(new \RuntimeException('worker died'));
        $this->assertSame('queue_failure', $failedJob->fresh()->error_code);
    }

    public function test_retention_removes_old_runs_of_finished_incidents_only(): void
    {
        ['a' => $a, 'b' => $b] = $this->incidents();
        $a->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'active_fingerprint' => null])->save();
        $old = fn (Incident $i, string $status) => Investigation::query()->withoutGlobalScopes()->forceCreate(['team_id' => $i->team_id, 'incident_id' => $i->id, 'status' => $status, 'trigger' => 'opened', 'pipeline_version' => 1, 'created_at' => now()->subDays(400)]);

        $gone = $old($a, 'completed');
        $pending = $old($a, 'queued');
        $active = $old($b, 'completed');
        $recent = $this->investigateNow($a);

        config(['serverscope.retention.investigations_days' => 365]);
        $this->artisan('serverscope:prune')->assertSuccessful();

        $this->assertNull(Investigation::query()->withoutGlobalScopes()->find($gone->id));
        $this->assertNotNull(Investigation::query()->withoutGlobalScopes()->find($pending->id), 'pending runs are kept');
        $this->assertNotNull(Investigation::query()->withoutGlobalScopes()->find($active->id), 'runs of an active incident are kept');
        $this->assertNotNull(Investigation::query()->withoutGlobalScopes()->find($recent->id));
    }

    public function test_export_is_the_stored_snapshot(): void
    {
        ['a' => $a] = $this->incidents();
        $run = $this->investigateNow($a);

        $response = $this->actingAs($this->a['member'])->get(route('investigations.export', $run))->assertOk();
        $this->assertSame($run->result, $response->json('data.result'));
        $this->assertSame($run->input_digest, $response->json('data.input_digest'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('token_hash', $response->getContent());
    }

    public function test_nexus_links_the_incident_to_its_investigation(): void
    {
        ['a' => $a] = $this->incidents();
        $topology = app(TopologyBuilder::class)->build($this->a['team']->id);
        $node = collect($topology['nodes'])->firstWhere('key', 'domain:'.$this->a['domain']->id);

        $this->assertSame(route('incidents.investigation', $node['incidents']['primary']['id']), $node['incidents']['primary']['investigation_url']);
        $this->actingAs($this->a['member'])->get(route('nexus.index', ['focus' => 'server:'.$this->a['server']->id]))->assertOk();

        $run = $this->investigateNow($a);
        $this->actingAs($this->a['member'])->get(route('investigations.show', $run))->assertSee(route('nexus.index', ['focus' => $run->result['impact']['cause']]), false);
    }
}
