<?php

namespace Tests\Feature\Intelligence;

use App\Jobs\RunIncidentAnalysis;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Models\Investigation;
use App\Models\MetricSample;
use App\Services\Ai\AiProvider;
use App\Services\Incidents\IncidentInvestigator;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Intelligence\ContextBuilder;
use App\Services\Intelligence\InvestigationAiContract;
use App\Services\Intelligence\InvestigationContext;
use App\Services\Intelligence\Investigations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * NEXUS Intelligence phase 2: the AI Incident Investigator's contract for
 * incidents with a finished investigation (nexus.investigation.v1), its
 * validation, lifecycle, cost controls, isolation and presentation.
 */
class IntelligenceAiContractTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private CarbonImmutable $onset;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        config([
            'serverscope.ai.api_key' => 'sk-test-secret-key',
            'serverscope.ai.model' => 'test-model',
            'serverscope.ai.auto_min_severity' => null,
            'serverscope.intelligence.auto_triggers' => [],
        ]);
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();

        foreach ([$this->a, $this->b] as $t) {
            $t['team']->forceFill(['plan' => 'pro', 'plan_status' => 'active'])->save();
        }

        $this->onset = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A website incident after nginx stopped, with metrics; for both teams the same. */
    private function incident(string $team = 'a', string $fingerprint = 'http:ai'): Incident
    {
        $t = $this->{$team};
        MetricSample::query()->where('server_id', $t['server']->id)->delete();

        for ($at = $this->onset->subHours(30); $at->lt(now()); $at = $at->addMinutes(10)) {
            MetricSample::query()->insert(['server_id' => $t['server']->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'cpu_percent' => 12, 'memory_total_bytes' => 100, 'memory_used_bytes' => 30]);
        }

        DB::table('service_check_events')->insert(['service_check_id' => $t['service']->id, 'status' => 'stopped', 'previous_status' => 'running', 'detail' => "{$t['team']->slug} inactive token=abc123secret", 'recorded_at' => $this->onset->subMinutes(15)]);
        $incident = app(IncidentManager::class)->trigger(new Signal($fingerprint, 'http', 'critical', "{$t['domain']->name} down", null, $t['domain'], startedAt: $this->onset->toMutable(), teamId: $t['team']->id));
        $t['domain']->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'message' => 'refused', 'checked_at' => $this->onset->addMinutes(2)]);

        return $incident->fresh();
    }

    private function investigation(Incident $incident): Investigation
    {
        return app(Investigations::class)->request($incident, 'manual')->fresh();
    }

    /** Fake provider answering from the input it receives; $make gets the decoded input. */
    private function fakeAi(\Closure|int $make): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => is_int($make)
            ? Http::response(['error' => ['message' => 'x']], $make)
            : function (ClientRequest $request) use ($make) {
                $content = $request->data()['messages'][1]['content'];
                $input = json_decode(substr($content, strpos($content, "\n<investigation>\n") + 17, -strlen("\n</investigation>")), true);

                return Http::response(['model' => 'test-model-2026', 'choices' => [['message' => ['content' => json_encode($make($input))], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 200]]);
            }]);
    }

    private function answer(array $input, array $override = []): array
    {
        $c1 = $input['candidates'][0];

        return [
            'summary' => 'nginx stopped 15 minutes before the website failed.',
            'observed_facts' => [['statement' => 'nginx stopped before the onset', 'evidence' => [...$c1['supporting_evidence'], 'E9999']], ['statement' => 'Invented', 'evidence' => ['X1']]],
            'leading' => ['candidate' => 'C1', 'explanation' => 'Timing and dependency fit; not proof.', 'supporting_evidence' => $c1['supporting_evidence'], 'contradicting_evidence' => ['E9998']],
            'alternatives' => [['candidate' => 'C99', 'explanation' => 'made up', 'supporting_evidence' => [], 'contradicting_evidence' => []]],
            'confidence' => ['level' => 'medium', 'explanation' => 'One structural relation.'],
            'uncertainties' => ['No application logs.'],
            'next_steps' => [['step' => 'Read the nginx error log around the onset.', 'evidence' => $c1['supporting_evidence']]],
            'limitations' => ['Metrics only every 10 minutes.'],
            'historical_references' => [['incident_id' => 999999, 'relevance' => 'invented']],
            ...$override,
        ];
    }

    private function prompt(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (ClientRequest $r) => str_contains($r->url(), 'api.openai.com'))->data();
    }

    public function test_the_input_is_the_investigation_bounded_and_in_separate_namespaces(): void
    {
        $incident = $this->incident();
        $run = $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input));

        $analysis = app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh();
        $input = $analysis->evidence;

        $this->assertSame(InvestigationAiContract::CONTRACT, $input['contract']);
        $this->assertSame($run->id, $analysis->investigation_id);
        $this->assertSame($run->id, $input['investigation']['id']);
        $this->assertSame('service_failed', $input['candidates'][0]['code']);
        $this->assertSame('medium', $input['candidates'][0]['deterministic_confidence']);

        // Candidates C…, evidence E… (the snapshot's own keys), history H…; never mixed.
        $this->assertSame(range(1, count($input['candidates'])), array_map(fn ($c) => (int) substr($c['id'], 1), $input['candidates']));
        $this->assertNotContains('insufficient_evidence', array_column($input['candidates'], 'code'), 'unknown is the model\'s own option');
        $keys = array_column($run->result['evidence'], 'key');

        foreach ($input['evidence'] as $e) {
            $this->assertMatchesRegularExpression('/^E\d+$/', $e['id']);
            $this->assertContains($e['id'], $keys);
        }

        foreach ($input['candidates'] as $c) {
            foreach ([...$c['supporting_evidence'], ...$c['contradicting_evidence']] as $ref) {
                $this->assertContains($ref, array_column($input['evidence'], 'id'));
            }
        }

        $this->assertLessThanOrEqual(InvestigationAiContract::MAX_EVIDENCE, count($input['evidence']));
        $this->assertLessThanOrEqual(InvestigationAiContract::MAX_IMPACT, count($input['impact']['resources']));

        // The prompt: the new rules and schema, the data in tags, the configured model.
        $prompt = $this->prompt();
        $this->assertSame(InvestigationAiContract::INSTRUCTIONS, $prompt['messages'][0]['content']);
        $this->assertStringContainsString('<investigation>', $prompt['messages'][1]['content']);
        $this->assertSame('investigation_analysis', $prompt['response_format']['json_schema']['name']);
        $this->assertSame('test-model', $prompt['model']);

        // Only this team's data, no secrets.
        $sent = json_encode($prompt);
        $this->assertStringNotContainsString('bravo', $sent);
        $this->assertStringNotContainsString('abc123secret', $sent);
        $this->assertStringNotContainsString('sk-test-secret-key', $sent);
        $this->assertStringNotContainsString('token_hash', $sent);
    }

    public function test_a_valid_answer_is_stored_with_unknown_references_removed_and_counted(): void
    {
        $incident = $this->incident();
        $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input));

        $analysis = app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh();
        $r = $analysis->result;

        $this->assertSame('completed', $analysis->status);
        $this->assertSame(InvestigationAiContract::CONTRACT, $r['contract']);
        $this->assertSame('C1', $r['leading']['candidate']);
        $this->assertSame('service_failed', $r['leading']['code']);
        $this->assertSame([], $r['leading']['contradicting'], 'E9998 was not supplied');
        $this->assertSame([], $r['alternatives'], 'C99 was not supplied');
        $this->assertCount(1, $r['observed_facts']);
        $this->assertNotContains('E9999', $r['observed_facts'][0]['evidence']);
        $this->assertSame([], $r['historical_references']);
        // E9999, E9998, the fact without evidence, C99 and incident 999999.
        $this->assertSame(5, $r['removed_references']);
    }

    public function test_contradicting_evidence_and_alternatives_are_kept_when_valid(): void
    {
        $incident = $this->incident();
        $run = $this->investigation($incident);
        $this->fakeAi(function ($input) {
            $all = array_column($input['evidence'], 'id');

            return $this->answer($input, [
                'leading' => ['candidate' => 'C1', 'explanation' => 'x', 'supporting_evidence' => [$all[0]], 'contradicting_evidence' => [$all[1]]],
                'alternatives' => count($input['candidates']) > 1 ? [['candidate' => 'C2', 'explanation' => 'less likely', 'supporting_evidence' => [$all[1]], 'contradicting_evidence' => [$all[0]]]] : [],
                'observed_facts' => [], 'historical_references' => [],
            ]);
        });

        $r = app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh()->result;

        $this->assertCount(1, $r['leading']['contradicting']);
        $this->assertSame(0, $r['removed_references']);

        if (count(array_filter($run->result['hypotheses'], fn ($h) => $h['code'] !== 'insufficient_evidence')) > 1) {
            $this->assertSame('C2', $r['alternatives'][0]['candidate']);
            $this->assertCount(1, $r['alternatives'][0]['contradicting']);
        }
    }

    public function test_the_model_may_answer_unknown_and_an_unsupplied_leading_candidate_is_rejected(): void
    {
        $incident = $this->incident();
        $run = $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input, ['leading' => ['candidate' => 'unknown', 'explanation' => 'Not enough evidence.', 'supporting_evidence' => [], 'contradicting_evidence' => []], 'confidence' => ['level' => 'insufficient', 'explanation' => 'x']]));

        $unknown = app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh();
        $this->assertSame('completed', $unknown->status);
        $this->assertSame('unknown', $unknown->result['leading']['candidate']);
        $this->assertNull($unknown->result['leading']['code']);

        $other = $this->incident('a', 'http:ai2');
        $this->investigation($other);
        $this->fakeAi(fn ($input) => $this->answer($input, ['leading' => ['candidate' => 'C42', 'explanation' => 'x', 'supporting_evidence' => [], 'contradicting_evidence' => []]]));

        $rejected = app(IncidentInvestigator::class)->requestBest($other, 'manual', $this->a['admin'])->fresh();
        $this->assertSame('failed', $rejected->status);
        $this->assertSame('invalid_response', $rejected->error_code);
        $this->assertNull($rejected->result);

        // The deterministic investigation is untouched.
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($run->result, $run->fresh()->result);
    }

    public function test_provider_failures_fail_only_the_analysis(): void
    {
        foreach ([429 => 'rate_limited', 500 => 'provider_error', 401 => 'unavailable'] as $status => $kind) {
            $incident = $this->incident('a', "http:fail{$status}");
            $run = $this->investigation($incident);
            $this->fakeAi($status);

            $analysis = app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh();

            $this->assertSame(['failed', $kind], [$analysis->status, $analysis->error_code]);
            $this->assertSame('completed', $run->fresh()->status);
        }

        $incident = $this->incident('a', 'http:timeout');
        $this->investigation($incident);
        Http::swap(new Factory);
        Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->assertSame('timeout', app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin'])->fresh()->error_code);

        // The page still shows the deterministic result and the failure.
        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()
            ->assertSee('data-testid="root-cause"', false)
            ->assertSee('data-ai-status="failed"', false);
    }

    public function test_the_same_content_is_never_analysed_twice(): void
    {
        $incident = $this->incident();
        $first = $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input));

        $analysis = app(IncidentInvestigator::class)->requestForInvestigation($first, 'auto');
        // A queue redelivery of the finished job does nothing.
        (new RunIncidentAnalysis($analysis->id))->handle(app(IncidentInvestigator::class));
        $this->assertSame($analysis->id, app(IncidentInvestigator::class)->requestForInvestigation($first, 'auto')->id);

        // A rerun of the investigation on unchanged evidence: same content, same analysis.
        $this->travel(2)->minutes();
        $second = app(Investigations::class)->request($incident, 'manual')->fresh();
        $this->assertNotSame($first->id, $second->id);
        $same = app(IncidentInvestigator::class)->requestForInvestigation($second, 'auto');

        if ($second->input_digest === $first->input_digest) {
            $this->assertSame($analysis->id, $same->id);
        }

        $this->assertCount(IncidentAnalysis::query()->withoutGlobalScopes()->count(), Http::recorded());
    }

    public function test_plan_gating_cooldown_and_admin_permission_are_unchanged(): void
    {
        $incident = $this->incident();
        $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input));

        // Members may not ask; admins may, once per cooldown.
        $this->actingAs($this->a['member'])->post(route('incidents.analysis', $incident))->assertForbidden();
        $this->actingAs($this->a['admin'])->post(route('incidents.analysis', $incident))->assertRedirect(route('incidents.show', $incident).'#ai-analysis');
        $this->assertTrue(IncidentAnalysis::query()->withoutGlobalScopes()->sole()->interpretsInvestigation());
        $this->assertSame('cooldown', app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin']));

        // Another team's incident does not exist for this admin.
        $this->actingAs($this->a['admin'])->post(route('incidents.analysis', $this->b['incident']))->assertNotFound();

        // Free plan: refused, also through a direct request.
        $this->a['team']->forceFill(['plan' => 'free'])->save();
        $free = $this->incident('a', 'http:free');
        $this->investigation($free);
        $this->assertSame('plan', app(IncidentInvestigator::class)->requestBest($free, 'manual', $this->a['admin']));
        $this->actingAs($this->a['admin'])->post(route('incidents.analysis', $free))->assertSessionHas('error');
        $this->assertSame(1, IncidentAnalysis::query()->withoutGlobalScopes()->count());

        // The team hourly limit applies to both contracts together.
        $this->a['team']->forceFill(['plan' => 'pro'])->save();
        config(['serverscope.ai.max_per_team_per_hour' => 1]);
        $this->assertSame('limit', app(IncidentInvestigator::class)->requestBest($free, 'manual', $this->a['admin']));
    }

    public function test_automatic_analysis_follows_the_opened_investigation_once(): void
    {
        config(['serverscope.ai.auto_min_severity' => 'high', 'serverscope.intelligence.auto_triggers' => ['opened', 'reopened', 'resolved']]);
        $this->fakeAi(fn ($input) => $this->answer($input, ['leading' => ['candidate' => $input['candidates'] ? 'C1' : 'unknown', 'explanation' => 'x', 'supporting_evidence' => [], 'contradicting_evidence' => []]]));

        $incident = app(IncidentManager::class)->trigger(new Signal('http:auto', 'http', 'critical', 'alpha down', null, $this->a['domain'], teamId: $this->a['team']->id));

        $analysis = IncidentAnalysis::query()->withoutGlobalScopes()->sole();
        $run = Investigation::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['auto', $run->id, 'opened'], [$analysis->trigger, $analysis->investigation_id, $run->trigger]);
        $this->assertTrue($analysis->interpretsInvestigation());

        // Resolve and reopen investigate again, but never call the AI again.
        app(IncidentManager::class)->recover('http:auto', 'ok');
        app(IncidentManager::class)->trigger(new Signal('http:auto', 'http', 'critical', 'alpha down', null, $this->a['domain'], teamId: $this->a['team']->id));
        $this->assertSame(3, Investigation::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, IncidentAnalysis::query()->withoutGlobalScopes()->count());
        $this->assertCount(1, Http::recorded());

        // Not enough severity: investigated, not analysed.
        app(IncidentManager::class)->trigger(new Signal('http:low', 'http', 'warning', 'alpha slow', null, $this->a['server'], teamId: $this->a['team']->id));
        $this->assertSame(1, IncidentAnalysis::query()->withoutGlobalScopes()->count());

        // Free plan: investigated, not analysed.
        $this->b['team']->forceFill(['plan' => 'free'])->save();
        app(IncidentManager::class)->trigger(new Signal('http:b', 'http', 'critical', 'bravo down', null, $this->b['domain'], teamId: $this->b['team']->id));
        $this->assertSame(0, IncidentAnalysis::query()->withoutGlobalScopes()->where('team_id', $this->b['team']->id)->count());
        $this->assertSame(1, Investigation::query()->withoutGlobalScopes()->where('team_id', $this->b['team']->id)->count());

        // No resource, so no investigation: the legacy contract, as before.
        app(IncidentManager::class)->trigger(new Signal('manual:x', 'manual', 'critical', 'Manual', null, null, teamId: $this->a['team']->id));
        $legacy = IncidentAnalysis::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertFalse($legacy->interpretsInvestigation());
        $this->assertNull($legacy->investigation_id);
    }

    public function test_a_failed_investigation_falls_back_to_the_legacy_contract(): void
    {
        config(['serverscope.ai.auto_min_severity' => 'high', 'serverscope.intelligence.auto_triggers' => ['opened']]);
        $this->fakeAi(fn () => ['summary' => 'legacy', 'observed_facts' => [], 'root_causes' => [], 'investigation_steps' => [], 'missing_information' => [], 'confidence' => ['level' => 'insufficient', 'explanation' => 'x'], 'historical_references' => []]);
        // The run itself fails (inside the runner), not the queue.
        $this->app->bind(ContextBuilder::class, fn () => new class extends ContextBuilder
        {
            public function __construct() {}

            public function build(Incident $incident, CarbonImmutable $asOf): InvestigationContext
            {
                throw new \RuntimeException('boom');
            }
        });

        app(IncidentManager::class)->trigger(new Signal('http:fallback', 'http', 'critical', 'alpha down', null, $this->a['domain'], teamId: $this->a['team']->id));

        $this->assertSame(['failed', 'internal_error'], [Investigation::query()->withoutGlobalScopes()->sole()->status, Investigation::query()->withoutGlobalScopes()->sole()->error_code]);
        $analysis = IncidentAnalysis::query()->withoutGlobalScopes()->sole();
        $this->assertFalse($analysis->interpretsInvestigation());
        $this->assertSame('completed', $analysis->status);
    }

    public function test_the_root_cause_section_shows_both_layers_escaped_in_both_languages(): void
    {
        $incident = $this->incident();
        $run = $this->investigation($incident);
        $this->fakeAi(fn ($input) => $this->answer($input, ['summary' => 'nginx <script>alert(1)</script> stopped']));
        app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin']);

        foreach (['en', 'nl'] as $locale) {
            $this->a['member']->forceFill(['locale' => $locale])->save();
            $page = $this->actingAs($this->a['member']->fresh())->get(route('incidents.show', $incident))->assertOk();

            $page->assertSee('data-testid="root-cause"', false)
                ->assertSee('data-ai-status="completed"', false)
                ->assertSee(trans('investigations.rca.title', [], $locale))
                ->assertSee(trans('investigations.rca.deterministic', [], $locale))
                ->assertSee(trans('investigations.rca.ai', [], $locale))
                ->assertSee(trans('investigations.rca.removed', ['count' => 5], $locale))
                ->assertSee('nginx &lt;script&gt;alert(1)&lt;/script&gt; stopped', false)
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertSee(route('investigations.show', $run))
                ->assertDontSee('data-testid="ai-analysis"', false);

            // The full investigation page shows the same interpretation.
            $this->actingAs($this->a['member']->fresh())->get(route('investigations.show', $run))->assertOk()
                ->assertSee('data-testid="investigation-ai"', false)
                ->assertSee('nginx &lt;script&gt;alert(1)&lt;/script&gt; stopped', false);
        }
    }

    public function test_pending_unavailable_and_plan_states_render(): void
    {
        $incident = $this->incident();
        $this->investigation($incident);

        // AI not configured.
        config(['serverscope.ai.api_key' => null]);
        $this->actingAs($this->a['admin'])->get(route('incidents.show', $incident))->assertOk()->assertSee('data-ai-status="unavailable"', false);

        // Pending.
        config(['serverscope.ai.api_key' => 'sk-test-secret-key']);
        $this->app->forgetInstance(AiProvider::class);
        Http::swap(new Factory);
        Queue::fake();
        app(IncidentInvestigator::class)->requestBest($incident, 'manual', $this->a['admin']);
        $this->actingAs($this->a['admin'])->get(route('incidents.show', $incident))->assertOk()->assertSee('data-ai-status="queued"', false);

        // A stored interpretation keeps showing after AI is switched off.
        IncidentAnalysis::query()->withoutGlobalScopes()->update(['status' => 'completed', 'result' => json_encode(InvestigationAiContract::validate(['summary' => 'Stored earlier.', 'leading' => ['candidate' => 'unknown', 'explanation' => 'x', 'supporting_evidence' => [], 'contradicting_evidence' => []], 'confidence' => ['level' => 'low', 'explanation' => 'x']], IncidentAnalysis::query()->withoutGlobalScopes()->sole()->evidence))]);
        config(['serverscope.ai.api_key' => null]);
        $this->app->forgetInstance(AiProvider::class);
        $this->actingAs($this->a['admin'])->get(route('incidents.show', $incident))->assertOk()->assertSee('data-ai-status="completed"', false)->assertSee('Stored earlier.')->assertDontSee(__('ai_analysis.unavailable'));

        // Free plan.
        config(['serverscope.ai.api_key' => 'sk-test-secret-key']);
        $this->a['team']->forceFill(['plan' => 'free'])->save();
        IncidentAnalysis::query()->withoutGlobalScopes()->delete();
        $this->actingAs($this->a['admin'])->get(route('incidents.show', $incident))->assertOk()->assertSee('data-ai-status="plan"', false);
    }
}
