<?php

namespace Tests\Feature\Releases;

use App\Http\Controllers\Api\MetricSampleController;
use App\Http\Middleware\AuthenticateAgent;
use App\Models\AgentRelease;
use App\Models\AgentUpdateRequest;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentCredentialService;
use App\Services\AgentUpdates;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\SignedReleases;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class UpdateApiTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;
    use TwoTeams;

    private array $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTwoTeams();
        $this->key = $this->releaseKey();
        $this->trustReleaseKeys($this->key);
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
        $this->import('0.3.0');
        $this->import('0.3.1');
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function import(string $version): void
    {
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle($version, $this->key), '--admin' => 'platform@example.test'])->assertSuccessful();
    }

    private function tokenOf(Server $server): string
    {
        return app(AgentCredentialService::class)->issue($server, 'test')['token'];
    }

    private function checkIn(string $token, array $query = ['installed' => '0.3.0', 'updater' => '1', 'state' => 'enabled'])
    {
        return $this->withToken($token)->getJson(route('agent.update.check', $query));
    }

    private function requestFor(Server $server, string $version = '0.3.1'): AgentUpdateRequest
    {
        $admin = $server->team_id === $this->a['team']->id ? $this->a['admin'] : $this->b['admin'];

        return Tenant::run($server->team_id, fn () => app(AgentUpdates::class)->request($server, AgentRelease::query()->where('version', $version)->sole(), $admin, 'manual'));
    }

    public function test_the_check_in_is_recorded_and_offers_nothing_by_default(): void
    {
        $server = $this->a['server'];
        $this->checkIn($this->tokenOf($server))->assertOk()->assertExactJson(['data' => ['update' => null]]);

        $server->refresh();
        $this->assertSame(1, $server->updater_version);
        $this->assertSame('enabled', $server->updater_state);
        $this->assertNotNull($server->updater_last_seen_at);
        $this->assertFalse($server->auto_update_enabled);
    }

    public function test_a_requested_update_is_offered_only_to_its_own_server(): void
    {
        $request = $this->requestFor($this->a['server']);

        $this->checkIn($this->tokenOf($this->b['server']))->assertOk()->assertExactJson(['data' => ['update' => null]]);
        $this->checkIn($this->tokenOf($this->a['server']))->assertOk()->assertExactJson(['data' => ['update' => ['request_id' => $request->id, 'version' => '0.3.1']]]);
    }

    public function test_a_server_can_only_report_its_own_requests(): void
    {
        $request = $this->requestFor($this->a['server']);
        $other = $this->tokenOf($this->b['server']);

        $this->withToken($other)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'in_progress'])->assertNotFound();
        $this->assertSame('pending', $request->fresh()->status);

        $own = $this->tokenOf($this->a['server']);
        $this->withToken($own)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'in_progress'])->assertOk();
        $this->withToken($own)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'succeeded', 'installed' => '0.3.1'])->assertOk();

        $request->refresh();
        $this->assertSame('succeeded', $request->status);
        $this->assertNotNull($request->started_at);
        $this->assertNotNull($request->finished_at);

        // A finished request cannot be reported again.
        $this->withToken($own)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'rolled_back', 'reason' => 'no_sample'])->assertStatus(409);
        $this->assertSame('succeeded', $request->fresh()->status);
    }

    public function test_results_and_reasons_are_recorded_and_audited(): void
    {
        $token = $this->tokenOf($this->a['server']);

        foreach (['rolled_back' => 'no_sample', 'rejected' => 'bad_signature', 'failed' => 'download_failed'] as $status => $reason) {
            AgentUpdateRequest::withoutGlobalScopes()->update(['status' => 'expired']);
            $request = $this->requestFor($this->a['server']);
            $this->withToken($token)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'in_progress'])->assertOk();
            $this->withToken($token)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => $status, 'reason' => $reason, 'installed' => '0.3.0'])->assertOk();
            $this->assertSame([$status, $reason], [$request->fresh()->status, $request->fresh()->reason]);

            $entry = AuditLog::withoutGlobalScopes()->where('action', "agent_update.{$status}")->latest('id')->first();
            $this->assertNotNull($entry, $status);
            $this->assertSame($this->a['team']->id, $entry->team_id);
            $this->assertSame('0.3.1', $entry->properties['version']);
            $this->assertSame($reason, $entry->properties['reason']);
        }
    }

    public function test_unknown_check_in_values_are_refused_and_not_stored(): void
    {
        $token = $this->tokenOf($this->a['server']);
        $bad = [
            ['installed' => '0.3', 'updater' => '1', 'state' => 'enabled'],
            ['installed' => '0.3.0; rm -rf /', 'updater' => '1', 'state' => 'enabled'],
            ['installed' => '0.3.0', 'updater' => '0', 'state' => 'enabled'],
            ['installed' => '0.3.0', 'updater' => 'een', 'state' => 'enabled'],
            ['installed' => '0.3.0', 'updater' => '1', 'state' => 'aan'],
            ['installed' => '0.3.0', 'updater' => '1'],
            ['installed' => '0.3.0', 'updater' => '1', 'state' => 'enabled', 'extra' => 'x'],
        ];

        foreach ($bad as $query) {
            $this->checkIn($token, $query)->assertStatus(422);
        }

        $server = $this->a['server']->fresh();
        $this->assertNull($server->updater_last_seen_at);
        $this->assertNull($server->updater_state);
        $this->assertNull($server->updater_version);
    }

    public function test_unknown_report_values_are_refused(): void
    {
        $request = $this->requestFor($this->a['server']);
        $token = $this->tokenOf($this->a['server']);

        foreach ([
            ['request_id' => $request->id, 'status' => 'done'],
            ['request_id' => $request->id, 'status' => 'rejected', 'reason' => 'whatever'],
            ['request_id' => $request->id, 'status' => 'failed', 'installed' => 'latest'],
            ['request_id' => 'abc', 'status' => 'failed'],
            ['request_id' => $request->id, 'status' => 'failed', 'message' => 'free text'],
            ['request_id' => $request->id, 'status' => 'pending'],
        ] as $body) {
            $this->withToken($token)->postJson(route('agent.update.report'), $body)->assertStatus(422);
        }

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_disabled_updater_is_recorded_and_gets_no_update(): void
    {
        $this->requestFor($this->a['server']);

        $this->checkIn($this->tokenOf($this->a['server']), ['installed' => '0.3.0', 'updater' => '1', 'state' => 'disabled'])->assertOk()->assertExactJson(['data' => ['update' => null]]);
        $this->assertSame('disabled', $this->a['server']->fresh()->updater_state);
    }

    public function test_automatic_updates_create_one_request_for_the_newest_release(): void
    {
        $server = $this->a['server'];
        $server->forceFill(['auto_update_enabled' => true])->save();
        $token = $this->tokenOf($server);

        $offer = $this->checkIn($token)->assertOk()->json('data.update');
        $this->assertSame('0.3.1', $offer['version']);
        $request = AgentUpdateRequest::withoutGlobalScopes()->sole();
        $this->assertSame(['auto', null, $server->team_id], [$request->source, $request->requested_by, $request->team_id]);

        // Asking again returns the same request.
        $this->assertSame($offer, $this->checkIn($token)->json('data.update'));
        $this->assertSame(1, AgentUpdateRequest::withoutGlobalScopes()->count());

        // A version that rolled back on this server is not tried again automatically.
        $this->withToken($token)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'in_progress'])->assertOk();
        $this->withToken($token)->postJson(route('agent.update.report'), ['request_id' => $request->id, 'status' => 'rolled_back', 'reason' => 'no_sample'])->assertOk();
        $this->assertNull($this->checkIn($token)->json('data.update'));

        // Already on the newest version: nothing to do.
        $this->assertNull($this->checkIn($token, ['installed' => '0.3.1', 'updater' => '1', 'state' => 'enabled'])->json('data.update'));
    }

    public function test_withdrawn_or_stale_requests_are_no_longer_offered(): void
    {
        $request = $this->requestFor($this->a['server']);
        $token = $this->tokenOf($this->a['server']);

        $this->artisan('serverscope:release:withdraw', ['version' => '0.3.1', '--admin' => 'platform@example.test', '--reason' => 'fout'])->assertSuccessful();
        $this->assertSame(['expired', 'withdrawn'], [$request->fresh()->status, $request->fresh()->reason]);
        $this->assertNull($this->checkIn($token)->json('data.update'));

        $this->import('0.3.2');
        $stale = $this->requestFor($this->a['server'], '0.3.2');
        $this->travel(25)->hours();
        $this->assertNull($this->checkIn($token)->json('data.update'));
        $this->assertSame('expired', $stale->fresh()->status);
    }

    public function test_update_endpoints_need_a_valid_credential_and_have_their_own_rate_limit(): void
    {
        $this->getJson(route('agent.update.check', ['installed' => '0.3.0', 'updater' => '1', 'state' => 'enabled']))->assertUnauthorized();
        $this->postJson(route('agent.update.report'), ['request_id' => 1, 'status' => 'failed'])->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->getJson(route('agent.update.check', ['installed' => '0.3.0', 'updater' => '1', 'state' => 'enabled']))->assertUnauthorized();

        $first = $this->tokenOf($this->a['server']);
        $statuses = [];

        for ($i = 0; $i < 31; $i++) {
            $statuses[] = $this->checkIn($first)->getStatusCode();
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertSame(429, end($statuses));

        // Another credential from the same address and the metrics endpoint
        // are not affected; the same holds for /api/agent/self.
        $this->checkIn($this->tokenOf($this->b['server']))->assertOk();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($first)->getJson(route('agent.self'))->assertOk();
        }

        $this->withToken($first)->getJson(route('agent.self'))->assertStatus(429);
        $this->withToken($this->tokenOf($this->b['server']))->getJson(route('agent.self'))->assertOk();
        $this->travel(61)->seconds();
        $this->withToken($first)->getJson(route('agent.self'))->assertOk();
        $this->withToken($first)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 1])->assertCreated();
    }

    public function test_the_existing_agent_api_is_unchanged(): void
    {
        $metrics = Route::getRoutes()->getByName('agent.metrics.store');
        $this->assertSame(['POST'], $metrics->methods());
        $this->assertSame('api/agent/metrics', $metrics->uri());
        $this->assertSame(MetricSampleController::class.'@store', $metrics->getActionName());
        $this->assertSame(['api', AuthenticateAgent::class, 'throttle:60,1'], $metrics->gatherMiddleware());

        $token = $this->tokenOf($this->a['server']);
        $this->withToken($token)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 1, 'agent_version' => '0.2.0'])->assertCreated();

        // /api/agent/self keeps every field install.sh 0.2.0 reads and only adds agent_version.
        $self = $this->withToken($token)->getJson(route('agent.self'))->assertOk()->json('data');
        $this->assertSame(['server_id', 'server_name', 'status', 'last_seen_at', 'agent_version'], array_keys($self));
        $this->assertSame('0.2.0', $self['agent_version']);
    }
}
