<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\ApiToken;
use App\Models\Deployment;
use App\Models\MetricSample;
use App\Services\Analytics\MetricRollups;
use App\Services\Deployments\DeployJudge;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Incidents\TimeMachine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class DeployJudgeTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private CarbonImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app()->setLocale('en');
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
        $this->at = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');
        // Start from a clean history for the alpha resources.
        MetricSample::query()->delete();
        DB::table('domain_checks')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function record(array $body, ?string $plain = null, array $headers = [])
    {
        // Headers stick to the test client; start clean for every request.
        $this->flushHeaders();

        return $this->withHeaders([...$this->apiHeaders($plain ?? $this->a['plain']), ...$headers])->postJson('/api/v1/deployments', $body);
    }

    private function body(array $extra = []): array
    {
        return ['application' => 'shop', 'version' => '1.4.2', 'commit_sha' => 'ABCDEF1234', 'deployed_at' => $this->at->toIso8601String(), 'resources' => [['type' => 'server', 'id' => $this->a['server']->id], ['type' => 'domain', 'id' => $this->a['domain']->id]], ...$extra];
    }

    private function deployment(): Deployment
    {
        $id = $this->record($this->body())->assertCreated()->json('data.id');

        return Deployment::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /** HTTP checks every minute for the alpha domain; $after(i) gives [success, ms, status] after the deployment. */
    private function checks(callable $after): void
    {
        $rows = [];

        for ($i = -60; $i <= 60; $i++) {
            $at = $this->at->addMinutes($i);
            [$success, $ms, $status] = $i < 0 ? [true, 200 + $i % 5, 200] : $after($i);
            $rows[] = ['domain_id' => $this->a['domain']->id, 'type' => 'http', 'result' => $success ? 'up' : 'down', 'success' => $success, 'response_time_ms' => $ms, 'http_status_code' => $status, 'checked_at' => $at];
        }

        DB::table('domain_checks')->insert($rows);
    }

    /** Minute samples for the alpha server in [$from, $to); CPU $before until the deployment, $after from then. */
    private function samples(string $from, string $to, float $before, float $after): void
    {
        $rows = [];

        for ($at = CarbonImmutable::parse($from, 'UTC'); $at->lt(CarbonImmutable::parse($to, 'UTC')); $at = $at->addMinute()) {
            $rows[] = ['server_id' => $this->a['server']->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'cpu_percent' => ($at->lt($this->at) ? $before : $after) + $at->minute % 3, 'memory_total_bytes' => 1000, 'memory_used_bytes' => 400];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }
    }

    private function check(array $analysis, string $metric): array
    {
        return collect($analysis['checks'])->firstWhere('metric', $metric);
    }

    public function test_a_deployment_is_recorded_for_the_tokens_team_with_validated_resources(): void
    {
        $response = $this->record($this->body(['source' => 'github-actions', 'url' => 'https://github.com/acme/shop/actions/runs/1']))->assertCreated();

        $deployment = Deployment::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->a['team']->id, $deployment->team_id);
        $this->assertSame('abcdef1234', $deployment->commit_sha);
        $this->assertSame('production', $deployment->environment);
        $this->assertSame([['type' => 'server', 'id' => $this->a['server']->id], ['type' => 'domain', 'id' => $this->a['domain']->id]], $deployment->resourceRefs());
        $response->assertJsonPath('data.resources.0.type', 'server');
        $this->assertDatabaseHas('audit_logs', ['team_id' => $this->a['team']->id, 'action' => 'deployment.recorded']);
    }

    public function test_input_is_validated(): void
    {
        $this->record([])->assertUnprocessable()->assertJsonValidationErrors(['application', 'resources']);
        $this->record($this->body(['commit_sha' => 'not-a-sha']))->assertUnprocessable()->assertJsonValidationErrors('commit_sha');
        $this->record($this->body(['deployed_at' => now()->addHour()->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('deployed_at');
        $this->record($this->body(['deployed_at' => now()->subDays(40)->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('deployed_at');
        $this->record($this->body(['url' => 'javascript:alert(1)']))->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->record($this->body(['environment' => 'prod uction']))->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->record($this->body(['resources' => [['type' => 'database', 'id' => 1]]]))->assertUnprocessable()->assertJsonValidationErrors('resources.0.type');
        $this->record($this->body(['resources' => [['type' => 'server', 'id' => 999999]]]))->assertUnprocessable()->assertJsonValidationErrors('resources.0.id');

        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count());
    }

    public function test_tokens_need_the_write_ability_and_never_reach_another_team(): void
    {
        $this->postJson('/api/v1/deployments', $this->body())->assertUnauthorized();

        [, $readOnly] = ApiToken::issue($this->a['admin'], 'read only', ['read'], null, $this->a['team']->id);
        $this->record($this->body(), $readOnly)->assertForbidden();

        // A resource of another team is "unknown", indistinguishable from a missing one.
        $this->record($this->body(['resources' => [['type' => 'server', 'id' => $this->b['server']->id]]]))
            ->assertUnprocessable()->assertJsonPath('errors', ['resources.0.id' => ['Onbekende resource.']]);
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count());

        $deployment = $this->deployment();

        // Team B sees nothing of it, through the API or the web.
        $this->flushHeaders();
        $this->withHeaders($this->apiHeaders($this->b['plain']))->getJson("/api/v1/deployments/{$deployment->id}")->assertNotFound();
        $this->withHeaders($this->apiHeaders($this->b['plain']))->getJson('/api/v1/deployments')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->b['admin'])->get(route('deployments.show', $deployment))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('deployments.index'))->assertOk()->assertDontSee('shop');

        // Any member of team A may view.
        $this->actingAs($this->a['member'])->get(route('deployments.index'))->assertOk()->assertSee('shop');
        $this->actingAs($this->a['member'])->get(route('deployments.show', $deployment))->assertOk()->assertSee(trans('deployments.caveat', [], 'nl'));
        $this->withHeaders($this->apiHeaders($this->a['plain']))->getJson('/api/v1/deployments')->assertOk()->assertJsonPath('data.0.id', $deployment->id);
    }

    public function test_retries_do_not_create_duplicates(): void
    {
        $first = $this->record($this->body(), headers: ['Idempotency-Key' => 'deploy-shop-1'])->assertCreated();
        $this->record($this->body(), headers: ['Idempotency-Key' => 'deploy-shop-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->record($this->body(['version' => '9.9.9']), headers: ['Idempotency-Key' => 'deploy-shop-1'])->assertUnprocessable();

        // Without an Idempotency-Key, the same external_id is the same deployment.
        $id = $this->record($this->body(['external_id' => 'gh-123']))->assertCreated()->json('data.id');
        $this->record($this->body(['external_id' => 'gh-123']))->assertOk()->assertJsonPath('data.id', $id);

        // Another team may use the same external_id for its own deployment.
        $this->record([...$this->body(['external_id' => 'gh-123']), 'resources' => [['type' => 'server', 'id' => $this->b['server']->id]]], $this->b['plain'])->assertCreated();

        $this->assertSame(2, Deployment::query()->withoutGlobalScopes()->where('team_id', $this->a['team']->id)->count());
    }

    public function test_a_measured_regression_is_reported_with_its_evidence(): void
    {
        // After the deployment: slower, every fourth check a 502.
        $this->checks(fn (int $i) => $i % 4 === 0 ? [false, 900, 502] : [true, 650, 200]);
        $this->samples('2026-11-10 09:00:00', '2026-11-10 11:00:00', before: 20, after: 60);
        $deployment = $this->deployment();
        $incident = app(IncidentManager::class)->trigger(new Signal('http:dj', 'http', 'high', 'shop traag', null, $this->a['domain'], teamId: $this->a['team']->id));
        $incident->forceFill(['opened_at' => $this->at->addMinutes(12)])->save();

        $analysis = app(DeployJudge::class)->analyse($deployment);

        $this->assertSame('regression', $analysis['status']);
        $availability = $this->check($analysis, 'availability');
        $this->assertSame('regression', $availability['verdict']);
        $this->assertSame(100.0, $availability['before']);
        $this->assertSame(60, $availability['n_before']);
        $this->assertSame('regression', $this->check($analysis, 'http_errors')['verdict']);
        $response = $this->check($analysis, 'response_time_ms');
        $this->assertSame('regression', $response['verdict']);
        $this->assertSame(650.0, $response['after']);
        $cpu = $this->check($analysis, 'cpu_percent');
        $this->assertSame('regression', $cpu['verdict']);
        $this->assertSame('samples (median)', $cpu['basis']);
        $this->assertSame('no_change', $this->check($analysis, 'memory_percent')['verdict']);
        $this->assertSame([$incident->id], array_map(fn ($i) => $i['incident']->id, $analysis['incidents']));

        $this->withHeaders($this->apiHeaders($this->a['plain']))->getJson("/api/v1/deployments/{$deployment->id}")
            ->assertOk()->assertJsonPath('data.analysis.status', 'regression')->assertJsonPath('data.analysis.new_incidents.0.id', $incident->id)
            ->assertJsonPath('data.analysis.note', __('deployments.caveat'));
    }

    public function test_small_changes_are_not_regressions(): void
    {
        $this->checks(fn (int $i) => [true, 240, 200]); // +40 ms: below the minimum
        $this->samples('2026-11-10 09:00:00', '2026-11-10 11:00:00', before: 20, after: 28); // +8 points
        $analysis = app(DeployJudge::class)->analyse($this->deployment());

        $this->assertSame('no_regression', $analysis['status']);
        $this->assertSame(['no_change'], array_values(array_unique(array_column($analysis['checks'], 'verdict'))));
    }

    public function test_no_result_without_enough_data_or_before_the_window_has_passed(): void
    {
        // Too early: the hour after the deployment has not passed yet.
        Carbon::setTestNow('2026-11-10 10:30:00');
        $deployment = $this->deployment();
        $this->assertSame('pending', app(DeployJudge::class)->analyse($deployment)['status']);

        // Later, but only a handful of checks and no samples at all.
        Carbon::setTestNow('2026-11-10 12:00:00');
        foreach ([-5, -3, 3, 5] as $minute) {
            $this->a['domain']->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 100, 'checked_at' => $this->at->addMinutes($minute)]);
        }
        $analysis = app(DeployJudge::class)->analyse($deployment);

        $this->assertSame('insufficient_data', $analysis['status']);
        $this->assertSame(['insufficient_data'], array_values(array_unique(array_column($analysis['checks'], 'verdict'))));
        $this->assertSame(2, $this->check($analysis, 'availability')['n_before']);

        // A resource that has been deleted since.
        DB::table('deployment_resources')->where('deployment_id', $deployment->id)->delete();
        $this->assertSame('no_resources', app(DeployJudge::class)->analyse($deployment)['status']);
    }

    public function test_hourly_rollups_are_used_when_raw_samples_are_gone(): void
    {
        $this->samples('2026-11-10 06:00:00', '2026-11-10 12:00:00', before: 20, after: 60);
        app(MetricRollups::class)->rollup($this->a['server']);
        MetricSample::query()->where('collected_at', '<', '2026-11-10 11:00:00')->delete(); // retention

        Carbon::setTestNow('2026-11-10 13:30:00');
        $cpu = $this->check(app(DeployJudge::class)->analyse($this->deployment()), 'cpu_percent');

        $this->assertSame('hourly averages', $cpu['basis']);
        $this->assertSame('regression', $cpu['verdict']);
        $this->assertEqualsWithDelta(40.0, $cpu['change'], 0.01);
    }

    public function test_deployments_appear_in_time_machine_and_on_charts(): void
    {
        $deployment = $this->deployment();
        $incident = app(IncidentManager::class)->trigger(new Signal('http:tm-dj', 'http', 'high', 'shop onbereikbaar', null, $this->a['domain'], startedAt: $this->at->addMinutes(20)->toMutable(), teamId: $this->a['team']->id));
        $incident->forceFill(['opened_at' => $this->at->addMinutes(20)])->save();
        $this->samples('2026-11-10 08:00:00', '2026-11-10 12:00:00', before: 20, after: 20);
        $this->checks(fn (int $i) => [true, 200, 200]);

        $tm = app(TimeMachine::class)->build($incident->fresh());

        $event = collect($tm['events'])->firstWhere('title', 'Deployment shop 1.4.2 (production)');
        $this->assertNotNull($event);
        $this->assertSame(['before', 'change'], [$event['phase'], $event['category']]);
        $this->assertSame(route('deployments.show', $deployment), $event['url']);
        $this->assertContains('20 min before the onset — alpha-shop.example.com: Deployment shop 1.4.2 (production)', array_column($tm['findings'], 'text'));
        $this->assertNotContains(__('time_machine.gaps.no_deployments'), $tm['gaps']);
        $this->assertSame(24, $tm['metrics'][0]['markers'][0]['index']);

        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $incident))->assertOk()->assertSee('data-marker', false);
        $this->actingAs($this->a['member'])->get(route('domains.show', $this->a['domain']))->assertOk()->assertSee('data-marker', false);
        $this->actingAs($this->a['member'])->get(route('servers.show', $this->a['server']))->assertOk()->assertSee(route('deployments.show', $deployment));

        // Team B's deployments never show up in team A's Time Machine.
        $this->record([...$this->body(), 'application' => 'bravo-app', 'resources' => [['type' => 'server', 'id' => $this->b['server']->id]]], $this->b['plain'])->assertCreated();
        $this->actingAs($this->a['member'])->get(route('incidents.time-machine', $incident))->assertOk()->assertDontSee('bravo-app');
    }
}
