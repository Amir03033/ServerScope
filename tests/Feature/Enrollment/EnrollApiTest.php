<?php

namespace Tests\Feature\Enrollment;

use App\Models\AgentCredential;
use App\Models\AuditLog;
use App\Models\EnrollmentToken;
use App\Models\Server;
use App\Services\EnrollmentService;
use App\Services\ServerRegistrationService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * POST /api/agent/enroll: one-time tokens turn into a normal agent
 * credential for a new server in the token's team.
 */
class EnrollApiTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    private const NEUTRAL = 'Ongeldig of verlopen koppeltoken.';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->createTwoTeams();
    }

    /** @return array{0: EnrollmentToken, 1: string} */
    private function token(string $team = 'a', array $attributes = []): array
    {
        $tenant = $team === 'a' ? $this->a : $this->b;
        [$token, $plain] = Tenant::run($tenant['team'], fn () => app(EnrollmentService::class)->create($tenant['admin']));
        $token->forceFill($attributes)->save();

        return [$token, $plain];
    }

    private function facts(array $overrides = []): array
    {
        return ['hostname' => 'web-01.example.test', 'os' => 'Ubuntu 24.04.1 LTS', 'arch' => 'amd64', 'agent_version' => '0.2.0', ...$overrides];
    }

    private function enroll(string $plain, array $overrides = [])
    {
        return $this->postJson(route('agent.enroll'), ['token' => $plain, ...$this->facts($overrides)]);
    }

    public function test_a_valid_token_creates_a_pending_server_and_a_working_credential(): void
    {
        [$token, $plain] = $this->token();

        $response = $this->enroll($plain)->assertCreated()
            ->assertJsonStructure(['data' => ['server_id', 'server_name', 'agent_token']]);

        $server = Server::withoutGlobalScopes()->findOrFail($response->json('data.server_id'));
        $this->assertSame($this->a['team']->id, $server->team_id);
        $this->assertSame('web-01.example.test', $server->name);
        $this->assertSame('web-01.example.test', $server->hostname);
        $this->assertSame('Ubuntu 24.04.1 LTS', $server->operating_system);
        $this->assertSame('pending', $server->status);
        $this->assertSame('pending', $server->displayStatus());

        // A normal agent credential, stored hashed, made by the existing service.
        $agentToken = $response->json('data.agent_token');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $agentToken);
        $this->assertSame(1, AgentCredential::query()->where('server_id', $server->id)->where('token_hash', hash('sha256', $agentToken))->count());

        $token->refresh();
        $this->assertNotNull($token->used_at);
        $this->assertSame($server->id, $token->server_id);

        // The existing metrics contract is unchanged and marks it online.
        $this->withToken($agentToken)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 3.5])->assertCreated();
        $this->assertSame('online', $server->fresh()->displayStatus());
    }

    public function test_used_expired_revoked_unknown_and_malformed_tokens_get_the_same_answer(): void
    {
        [, $used] = $this->token();
        $this->enroll($used)->assertCreated();
        [, $expired] = $this->token('a', ['expires_at' => now()->subSecond()]);
        [, $revoked] = $this->token('a', ['revoked_at' => now()]);

        $bodies = [];

        foreach ([$used, $expired, $revoked, 'sse_'.str_repeat('0', 48), 'geen-token', ''] as $plain) {
            $response = $this->enroll($plain);
            $this->assertSame(401, $response->status(), "token '{$plain}'");
            $bodies[] = $response->getContent();
        }

        $this->assertSame([json_encode(['message' => self::NEUTRAL])], array_values(array_unique($bodies)));
        $this->assertSame(1, Server::withoutGlobalScopes()->where('hostname', 'web-01.example.test')->count());
    }

    public function test_the_team_comes_only_from_the_token(): void
    {
        [, $plain] = $this->token('b');

        $response = $this->postJson(route('agent.enroll'), ['token' => $plain, ...$this->facts(), 'team_id' => $this->a['team']->id, 'team' => $this->a['team']->id, 'server_id' => $this->a['server']->id])->assertCreated();

        $server = Server::withoutGlobalScopes()->findOrFail($response->json('data.server_id'));
        $this->assertSame($this->b['team']->id, $server->team_id);
        $this->assertNotSame($this->a['server']->id, $server->id);
        $this->assertSame(1, $this->a['server']->agentCredentials()->count(), 'nothing was added to team A');
    }

    public function test_a_token_can_only_be_used_once_even_when_called_twice(): void
    {
        [, $plain] = $this->token();

        $first = $this->enroll($plain);
        $second = $this->enroll($plain);

        $this->assertSame([201, 401], [$first->status(), $second->status()]);
        $this->assertSame(1, Server::withoutGlobalScopes()->where('hostname', 'web-01.example.test')->count());
    }

    public function test_redeeming_is_a_single_conditional_claim(): void
    {
        [$token, $plain] = $this->token();

        // Another request claims the token between the lookup and the claim:
        // the claim must fail instead of creating a second server.
        $claimed = false;
        DB::listen(function ($query) use ($token, &$claimed): void {
            if (! $claimed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'enrollment_tokens')) {
                $claimed = true;
                DB::table('enrollment_tokens')->where('id', $token->id)->update(['used_at' => now()]);
            }
        });

        $this->enroll($plain)->assertUnauthorized()->assertJsonPath('message', self::NEUTRAL);
        $this->assertSame(0, Server::withoutGlobalScopes()->where('hostname', 'web-01.example.test')->count());
    }

    public function test_a_failure_while_creating_the_server_leaves_the_token_usable(): void
    {
        [$token, $plain] = $this->token();
        $this->app->instance(ServerRegistrationService::class, new class extends ServerRegistrationService
        {
            public function __construct() {}

            public function register(array $serverData, string $credentialName = 'Primary agent'): array
            {
                throw new RuntimeException('database down');
            }
        });

        $this->enroll($plain)->assertStatus(500);
        $this->assertNull($token->fresh()->used_at);
        $this->assertSame(0, Server::withoutGlobalScopes()->where('hostname', 'web-01.example.test')->count());
    }

    public function test_input_is_validated_strictly(): void
    {
        // Many requests in one minute: the rate limit has its own test.
        config(['serverscope.enrollment.attempts_per_minute' => 100]);
        [, $plain] = $this->token();

        foreach ([
            ['hostname' => ''],
            ['hostname' => 'bad host name'],
            ['hostname' => str_repeat('a', 300)],
            ['hostname' => 'web;rm -rf /'],
            ['os' => str_repeat('x', 101)],
            ['os' => "Ubuntu\n24.04"],
            ['os' => '<script>alert(1)</script>'],
            ['arch' => 'i386'],
            ['arch' => 'x86_64; id'],
            ['agent_version' => 'latest'],
            ['agent_version' => '0.2.0; drop'],
        ] as $override) {
            $this->enroll($plain, $override)->assertStatus(422);
        }

        // Validation failures do not use up the token.
        $this->enroll($plain)->assertCreated();
    }

    public function test_enrollment_is_rate_limited_per_address(): void
    {
        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->enroll('sse_'.str_repeat('1', 48))->status();
        }

        $this->assertSame(401, $statuses[0]);
        $this->assertContains(429, $statuses);
        $this->assertLessThanOrEqual(10, count(array_filter($statuses, fn ($s) => $s === 401)));
    }

    public function test_install_polling_does_not_use_up_the_budget_of_metrics_or_revoke(): void
    {
        [, $plain] = $this->token();
        $agentToken = $this->enroll($plain)->json('data.agent_token');

        // install.sh polls up to 18 times; other servers send metrics from
        // the same address meanwhile.
        for ($i = 0; $i < 18; $i++) {
            $this->withToken($agentToken)->getJson(route('agent.self'))->assertOk();
        }

        for ($i = 0; $i < 50; $i++) {
            $this->withToken($this->agentTokenOf($this->a['server']))->postJson('/api/agent/metrics', ['collected_at' => now()->subSeconds($i)->toIso8601String(), 'cpu_percent' => 1]);
        }

        $this->withToken($this->agentTokenOf($this->b['server']))->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 1])->assertCreated();
        $this->withToken($agentToken)->postJson(route('agent.revoke'))->assertOk();
    }

    private function agentTokenOf(Server $server): string
    {
        return app(\App\Services\AgentCredentialService::class)->issue($server, 'test')['token'];
    }

    public function test_use_is_audited_in_the_tokens_team(): void
    {
        [$token, $plain] = $this->token('b');
        $server = $this->enroll($plain)->json('data.server_id');

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'enrollment.used')->sole();
        $this->assertSame($this->b['team']->id, $entry->team_id);
        $this->assertSame('server', $entry->auditable_type);
        $this->assertSame($server, $entry->auditable_id);
        $this->assertSame($token->id, $entry->properties['enrollment_token_id']);
    }

    public function test_an_agent_can_check_itself_and_revoke_only_its_own_credential(): void
    {
        [, $plain] = $this->token();
        $response = $this->enroll($plain);
        $agentToken = $response->json('data.agent_token');
        $serverId = $response->json('data.server_id');

        $this->withToken($agentToken)->getJson(route('agent.self'))->assertOk()
            ->assertJsonPath('data.server_id', $serverId)
            ->assertJsonPath('data.last_seen_at', null);

        $this->withToken($agentToken)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 1])->assertCreated();
        $this->assertNotNull($this->withToken($agentToken)->getJson(route('agent.self'))->json('data.last_seen_at'));

        // Revoking only affects the calling credential.
        $other = $this->a['server']->agentCredentials()->whereNull('revoked_at')->count();
        $this->withToken($agentToken)->postJson(route('agent.revoke'))->assertOk();
        $this->withToken($agentToken)->getJson(route('agent.self'))->assertUnauthorized();
        $this->assertSame($other, $this->a['server']->agentCredentials()->whereNull('revoked_at')->count());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'agent_credential.self_revoked')->where('team_id', $this->a['team']->id)->exists());

        $this->getJson(route('agent.self'))->assertUnauthorized();
    }
}
