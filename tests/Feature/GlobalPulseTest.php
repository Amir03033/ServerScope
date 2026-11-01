<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Probe;
use App\Models\ProbeCheck;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Dns\ResolverFactory;
use App\Services\Domains\DomainCheckRunner;
use App\Services\Probes\ProbeAgent;
use App\Services\Probes\ProbeSignature;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class GlobalPulseTest extends TestCase
{
    use RefreshDatabase;

    private Probe $probe;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $dns = (new FakeResolver)->host('example.com', '93.184.215.14')->host('internal.example', '10.1.2.3');
        $this->app->instance(Resolver::class, $dns);
        $this->app->instance(ResolverFactory::class, new class($dns) extends ResolverFactory
        {
            public function __construct(private Resolver $fake) {}

            public function forNameserver(string $ip): Resolver
            {
                return $this->fake;
            }
        });
        config(['serverscope.monitoring.requests_per_ip_per_minute' => 1000]);

        $this->secret = str_repeat('s', 64);
        $this->probe = new Probe(['name' => 'probe-1', 'location' => 'Frankfurt']);
        $this->probe->forceFill(['key' => str_repeat('a', 32), 'secret' => $this->secret, 'is_active' => true, 'last_seen_at' => now()])->save();
    }

    private function site(int $status): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('body', $status)]);
    }

    private function failingDomain(): Domain
    {
        $this->site(503);
        $domain = Domain::create(['name' => 'example.com', 'failure_threshold' => 2, 'recovery_threshold' => 2]);

        foreach (range(1, 3) as $i) {
            app(DomainCheckRunner::class)->run($domain, 'http');
        }

        return $domain->fresh();
    }

    /** A signed probe request; $override tampers with single parts. */
    private function probeCall(string $method, string $path, array $payload = [], array $override = [])
    {
        $body = $method === 'GET' ? '' : json_encode($payload);
        $headers = ProbeSignature::headers($override['key'] ?? $this->probe->key, $override['secret'] ?? $this->secret, $method, $path, $override['signed_body'] ?? $body);

        if (isset($override['timestamp'])) {
            $headers['X-Probe-Timestamp'] = (string) $override['timestamp'];
            $headers['X-Probe-Signature'] = ProbeSignature::sign($this->secret, $method, $path, $override['timestamp'], $headers['X-Probe-Nonce'], $body);
        }

        if (isset($override['nonce'])) {
            $headers['X-Probe-Nonce'] = $override['nonce'];
            $headers['X-Probe-Signature'] = ProbeSignature::sign($this->secret, $method, $path, (int) $headers['X-Probe-Timestamp'], $override['nonce'], $body);
        }

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $path, [], [], [], $server, $body);
    }

    private function probeResult(ProbeCheck $job, array $extra = []): array
    {
        return ['results' => [['id' => $job->id, 'checked_at' => now()->toIso8601String(), 'http_status_code' => 503, 'response_time_ms' => 120.5, ...$extra]]];
    }

    private function incident(Domain $domain): ?Incident
    {
        return Incident::query()->withoutGlobalScopes()->where('active_fingerprint', "domain:{$domain->id}:http")->first();
    }

    public function test_probe_requests_must_be_signed_fresh_and_unique(): void
    {
        $this->getJson('/api/probe/jobs')->assertUnauthorized();
        $this->probeCall('GET', '/api/probe/jobs', override: ['secret' => str_repeat('x', 64)])->assertUnauthorized();
        $this->probeCall('GET', '/api/probe/jobs', override: ['key' => str_repeat('b', 32)])->assertUnauthorized();
        $this->probeCall('GET', '/api/probe/jobs', override: ['timestamp' => time() - 301])->assertUnauthorized();
        $this->probeCall('POST', '/api/probe/results', ['results' => [['id' => 1]]], ['signed_body' => '{"results":[]}'])->assertUnauthorized();

        // A replayed nonce is refused even with a valid signature.
        $this->probeCall('GET', '/api/probe/jobs', override: ['nonce' => str_repeat('n', 32)])->assertOk();
        $this->probeCall('GET', '/api/probe/jobs', override: ['nonce' => str_repeat('n', 32)])->assertUnauthorized();

        $this->probe->forceFill(['is_active' => false])->save();
        $this->probeCall('GET', '/api/probe/jobs')->assertUnauthorized();
    }

    public function test_a_failure_waits_for_the_probe_and_a_confirmed_failure_opens_one_critical_incident(): void
    {
        $domain = $this->failingDomain();

        // Threshold reached three times, but no incident and only one job.
        $this->assertNull($this->incident($domain));
        $this->assertSame('down', $domain->http_status);
        $job = ProbeCheck::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['pending', $domain->team_id], [$job->status, $job->team_id]);

        // The job carries only what an availability check needs.
        $jobs = $this->probeCall('GET', '/api/probe/jobs')->assertOk()->json('data');
        $this->assertSame(['id', 'url', 'timeout_seconds', 'follow_redirects', 'expires_at'], array_keys($jobs[0]));
        $this->assertSame('https://example.com/', $jobs[0]['url']);
        $this->assertNotNull($this->probe->fresh()->last_seen_at);

        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job))->assertOk()->assertJsonPath('accepted', [$job->id]);

        $job->refresh();
        $this->assertSame(['completed', 'confirmed', false, 503, 120.5], [$job->status, $job->outcome, $job->success, $job->http_status_code, $job->response_time_ms]);
        $incident = $this->incident($domain);
        $this->assertSame(['critical', 'confirmed'], [$incident->severity, $incident->context['confirmation']]);

        // Further failures update the same incident; no new job, no duplicate.
        app(DomainCheckRunner::class)->run($domain, 'http');
        $this->assertSame(1, Incident::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, ProbeCheck::query()->withoutGlobalScopes()->count());

        // A result can only be applied once.
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job))->assertUnprocessable()->assertJsonPath('rejected', [$job->id]);
    }

    public function test_a_failure_seen_only_from_the_main_location_opens_a_warning_until_the_probe_confirms(): void
    {
        $domain = $this->failingDomain();
        $job = ProbeCheck::query()->withoutGlobalScopes()->sole();

        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job, ['http_status_code' => 200]))->assertOk();

        $this->assertSame('main_only', $job->fresh()->outcome);
        $incident = $this->incident($domain);
        $this->assertSame(['warning', 'main_only'], [$incident->severity, $incident->context['confirmation']]);
        $this->assertStringContainsString('Alleen vanaf de hoofdlocatie', $incident->description);

        // Still failing later: a recheck; when the probe now fails too, critical.
        $this->travel(11)->minutes();
        $this->probe->forceFill(['last_seen_at' => now()])->save();
        app(DomainCheckRunner::class)->run($domain, 'http');
        $this->assertSame('warning', $this->incident($domain)->severity, 'no escalation without confirmation');
        $recheck = ProbeCheck::query()->withoutGlobalScopes()->where('status', 'pending')->sole();

        $this->probeCall('POST', '/api/probe/results', $this->probeResult($recheck))->assertOk();
        $this->assertSame('critical', $this->incident($domain)->severity);
        $this->assertSame(1, Incident::query()->withoutGlobalScopes()->count());
    }

    public function test_without_a_healthy_probe_incidents_open_as_before(): void
    {
        // A stale probe: insufficient evidence, the incident opens immediately.
        $this->probe->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        $domain = $this->failingDomain();
        $incident = $this->incident($domain);
        $this->assertSame(['critical', 'unavailable'], [$incident->severity, $incident->context['confirmation']]);
        $this->assertSame(0, ProbeCheck::query()->withoutGlobalScopes()->count());

        // No probe configured at all: exactly the old behaviour.
        Incident::query()->withoutGlobalScopes()->delete();
        Domain::query()->forceDelete();
        Probe::query()->delete();
        $domain = $this->failingDomain();
        $this->assertSame('critical', $this->incident($domain)->severity);
        $this->assertArrayNotHasKey('confirmation', $this->incident($domain)->context);
    }

    public function test_an_unanswered_job_expires_into_a_normal_incident_and_late_results_are_rejected(): void
    {
        $domain = $this->failingDomain();
        $job = ProbeCheck::query()->withoutGlobalScopes()->sole();

        $this->travel(3)->minutes();
        $this->artisan('serverscope:probe:expire')->assertSuccessful();

        $this->assertSame(['expired', 'unavailable'], [$job->fresh()->status, $job->fresh()->outcome]);
        $this->assertSame(['critical', 'unavailable'], [$this->incident($domain)->severity, $this->incident($domain)->context['confirmation']]);
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job))->assertUnprocessable();
        $this->assertSame('expired', $job->fresh()->status);
    }

    public function test_a_failure_seen_only_by_the_probe_is_recorded_without_an_incident(): void
    {
        $domain = $this->failingDomain();
        $job = ProbeCheck::query()->withoutGlobalScopes()->sole();

        // The main monitor recovers before the probe answers.
        $this->site(200);
        app(DomainCheckRunner::class)->run($domain, 'http');
        app(DomainCheckRunner::class)->run($domain, 'http');
        $this->assertSame('up', $domain->fresh()->http_status);

        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job, ['http_status_code' => null, 'error_code' => 'timeout', 'message' => 'Time-out']))->assertOk();

        $this->assertSame(['probe_only', 'timeout'], [$job->fresh()->outcome, $job->fresh()->error_code]);
        $this->assertNull($this->incident($domain));
    }

    public function test_results_are_validated_and_bound_to_the_probes_own_jobs(): void
    {
        $domain = $this->failingDomain();
        $job = ProbeCheck::query()->withoutGlobalScopes()->sole();
        $other = new Probe(['name' => 'other']);
        $other->forceFill(['key' => str_repeat('c', 32), 'secret' => str_repeat('t', 64), 'last_seen_at' => now()])->save();

        // Another probe cannot answer this probe's job.
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job), ['key' => $other->key, 'secret' => str_repeat('t', 64)])->assertUnprocessable();
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job, ['checked_at' => now()->addHour()->toIso8601String()]))->assertUnprocessable();
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job, ['http_status_code' => null]))->assertUnprocessable();
        $this->probeCall('POST', '/api/probe/results', $this->probeResult($job, ['error_code' => 'Bad Code!']))->assertUnprocessable()->assertJsonValidationErrors('results.0.error_code');
        $this->probeCall('POST', '/api/probe/results', ['results' => array_fill(0, 21, ['id' => 1])])->assertUnprocessable();

        $this->assertSame('pending', $job->fresh()->status);
        $this->assertNull($this->incident($domain));
    }

    public function test_the_probe_enforces_ssrf_rules_and_sends_back_no_content(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $agent = app(ProbeAgent::class);
        // Even if the probe host's .env allowed private targets.
        config(['serverscope.monitoring.allow_private_targets' => true, 'serverscope.monitoring.allowed_cidrs' => ['10.0.0.0/8']]);
        config(['serverscope.probe.central_url' => 'https://central.test', 'serverscope.probe.key' => $this->probe->key, 'serverscope.probe.secret' => $this->secret]);

        $targets = ['http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://127.0.0.1:8080/', 'http://localhost/', 'https://internal.example/', 'http://example.com:22/'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'central.test/api/probe/jobs' => Http::response(['data' => [
                ...array_map(fn ($url, $i) => ['id' => $i + 1, 'url' => $url, 'timeout_seconds' => 60, 'follow_redirects' => true], $targets, array_keys($targets)),
                ['id' => 99, 'url' => 'ftp://example.com/file', 'timeout_seconds' => 5],
                ['id' => 'x', 'url' => 'https://example.com/'],
            ]]),
            'central.test/api/probe/results' => Http::response(['accepted' => [1], 'rejected' => []]),
        ]);

        $agent->runOnce();

        $posted = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (ClientRequest $r) => str_ends_with($r->url(), '/api/probe/results'));
        $results = $posted->data()['results'];
        $this->assertSame(range(1, count($targets)), array_column($results, 'id'), 'malformed jobs are skipped');
        $this->assertSame(['blocked'], array_values(array_unique(array_column($results, 'error_code'))));
        $this->assertSame(['id', 'checked_at', 'http_status_code', 'response_time_ms', 'error_code', 'message'], array_keys($results[0]));
        // Only the two calls to ServerScope were made: no request reached a target.
        $this->assertCount(2, Http::recorded());

        // The probe's requests are signed for exactly this method, path and body.
        $this->assertSame(ProbeSignature::sign($this->secret, 'POST', '/api/probe/results', (int) $posted->header('X-Probe-Timestamp')[0], $posted->header('X-Probe-Nonce')[0], $posted->body()), $posted->header('X-Probe-Signature')[0]);
    }

    public function test_the_probe_reports_status_and_timing_of_a_public_site(): void
    {
        $this->site(200);
        $result = app(ProbeAgent::class)->execute(['id' => 5, 'url' => 'https://example.com/', 'timeout_seconds' => 60]);

        $this->assertSame([5, 200, null], [$result['id'], $result['http_status_code'], $result['error_code']]);
        $this->assertIsFloat($result['response_time_ms']);
    }

    public function test_teams_only_see_their_own_confirmations(): void
    {
        $alpha = Team::query()->create(['name' => 'Alpha BV', 'slug' => 'alpha']);
        $bravo = Team::query()->create(['name' => 'Bravo BV', 'slug' => 'bravo']);
        $user = User::factory()->create();
        $user->teams()->sync([$alpha->id => ['role' => 'owner']]);
        $user->forceFill(['current_team_id' => $alpha->id])->save();
        $a = Domain::forceCreate(['team_id' => $alpha->id, 'name' => 'example.com']);
        $b = Domain::forceCreate(['team_id' => $bravo->id, 'name' => 'example.com']);

        foreach ([[$a, 'confirmed'], [$b, 'main_only'], [$b, 'main_only']] as [$domain, $outcome]) {
            $check = new ProbeCheck(['probe_id' => $this->probe->id, 'domain_id' => $domain->id, 'status' => 'completed', 'outcome' => $outcome, 'requested_at' => now(), 'expires_at' => now(), 'checked_at' => now(), 'error_code' => $domain->is($b) ? 'bravo_error' : 'alpha_error']);
            $check->save();
        }

        $this->assertSame([$alpha->id, $bravo->id, $bravo->id], ProbeCheck::query()->withoutGlobalScopes()->orderBy('id')->pluck('team_id')->all());
        Tenant::run($alpha->id, fn () => $this->assertSame([$a->id], ProbeCheck::query()->pluck('domain_id')->all()));

        $this->actingAs($user)->get(route('domains.show', $a))->assertOk()->assertSee('alpha_error')->assertDontSee('bravo_error');
        $this->actingAs($user)->get(route('domains.show', $b))->assertNotFound();
        // Team administrators see probe status with their own counts only.
        $this->actingAs($user)->get(route('system-health'))->assertOk()->assertSee('probe-1')->assertDontSee('bravo')
            ->assertSee(trans('probes.outcome.confirmed', [], 'nl').': 1')->assertSee(trans('probes.outcome.main_only', [], 'nl').': 0');
    }
}
