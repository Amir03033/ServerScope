<?php

namespace Tests\Feature\Enrollment;

use App\Models\AuditLog;
use App\Services\EnrollmentService;
use App\Services\ServerRegistrationService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Enrollment tokens and agent credentials never end up in Laravel logs,
 * the audit log or error responses: not on success, not on validation
 * errors, not on exceptions.
 */
class SecretLeakTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    /** @var array<int, string> */
    private array $logged = [];

    /** @var array<int, string> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config(['app.url' => 'https://monitor.example.test']);
        $this->createTwoTeams();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    private function enroll(string $plain, array $overrides = []): void
    {
        $response = $this->postJson(route('agent.enroll'), ['token' => $plain, 'hostname' => 'web-01.example.test', 'os' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '0.2.0', ...$overrides]);
        $this->responses[] = $response->getContent();
    }

    private function assertNowhere(string $secret, string $label): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($secret, $line, "{$label} in a log message");
        }

        foreach ($this->responses as $body) {
            $this->assertStringNotContainsString($secret, $body, "{$label} in an error response");
        }

        $audit = AuditLog::withoutGlobalScopes()->get()->toJson();
        $this->assertStringNotContainsString($secret, $audit, "{$label} in the audit log");
        $this->assertStringNotContainsString(substr($secret, 4, 24), $audit, "part of {$label} in the audit log");
    }

    public function test_tokens_never_leak_in_any_outcome(): void
    {
        // Created in the dashboard (shown once, never logged).
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'), ['label' => 'web']);
        $plain = (string) session('enrollment_token');
        $this->assertStringStartsWith('sse_', $plain);

        // Validation error while the token is valid.
        $this->enroll($plain, ['arch' => 'sparc']);

        // Exception while creating the server (the token stays usable).
        $real = app(ServerRegistrationService::class);
        $this->app->instance(ServerRegistrationService::class, new class extends ServerRegistrationService
        {
            public function __construct() {}

            public function register(array $serverData, string $credentialName = 'Primary agent'): array
            {
                throw new RuntimeException('database down');
            }
        });
        $this->enroll($plain);
        $this->app->instance(ServerRegistrationService::class, $real);

        // Success: the agent credential is only in this one response.
        $success = $this->postJson(route('agent.enroll'), ['token' => $plain, 'hostname' => 'web-01.example.test', 'os' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '0.2.0'])->assertCreated();
        $agentToken = $success->json('data.agent_token');

        // Reuse, revocation of the credential, and an exception with a
        // request that carries the agent credential.
        $this->enroll($plain);
        $this->withToken($agentToken)->postJson(route('agent.revoke'));
        $this->responses[] = $this->withToken($agentToken)->getJson(route('agent.self'))->getContent();

        $this->assertNotEmpty($this->logged, 'the exception was reported');
        $this->assertNowhere($plain, 'enrollment token');
        $this->assertNowhere($agentToken, 'agent credential');

        // The dashboard shows the token on the first visit only.
        $this->actingAs($this->a['admin'])->get(route('servers.add'));
        $this->assertStringNotContainsString($plain, $this->actingAs($this->a['admin'])->get(route('servers.add'))->getContent());
    }

    public function test_revoking_and_expiring_do_not_log_tokens(): void
    {
        [$token, $plain] = Tenant::run($this->a['team'], fn () => app(EnrollmentService::class)->create($this->a['admin']));
        $this->actingAs($this->a['admin'])->delete(route('enrollment-tokens.destroy', $token));
        $this->enroll($plain);

        $this->assertNowhere($plain, 'enrollment token');
    }
}
