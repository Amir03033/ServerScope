<?php

namespace Tests\Feature\Enrollment;

use App\Models\Alert;
use App\Models\Incident;
use App\Models\Server;
use App\Services\EnrollmentService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * A freshly enrolled server has status "pending" and no samples until
 * the agent's first report: it must show as pending everywhere and must
 * not raise a false "agent offline" incident meanwhile.
 */
class PendingServerTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    private Server $server;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->createTwoTeams();

        [, $plain] = Tenant::run($this->a['team'], fn () => app(EnrollmentService::class)->create($this->a['admin']));
        $result = app(EnrollmentService::class)->redeem($plain, ['hostname' => 'nieuw-01.example.test', 'os' => 'Debian GNU/Linux 12', 'arch' => 'arm64', 'agent_version' => '0.2.0'], '203.0.113.9');
        $this->server = $result['server'];
        $this->agentToken = $result['agent_token'];
    }

    public function test_a_new_server_shows_as_pending_everywhere(): void
    {
        $this->assertNull($this->server->last_seen_at);
        $this->assertSame('pending', $this->server->displayStatus());

        $admin = $this->a['admin'];
        $this->actingAs($admin)->get(route('servers.index', ['status' => 'pending']))->assertOk()->assertSee('nieuw-01.example.test');
        $this->actingAs($admin)->get(route('servers.index', ['status' => 'offline']))->assertOk()->assertDontSee('nieuw-01.example.test');
        $this->actingAs($admin)->get(route('servers.show', $this->server))->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, Tenant::run($this->a['team'], fn () => Server::query()->withDisplayStatus('pending')->count()));
    }

    public function test_no_offline_alert_or_incident_while_waiting_for_the_first_sample(): void
    {
        foreach ([1, 5, 15, 60] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->artisan('serverscope:check-agents')->assertSuccessful();
        }

        $this->assertSame(0, Alert::withoutGlobalScopes()->where('server_id', $this->server->id)->count());
        $this->assertSame(0, Incident::withoutGlobalScopes()->where('subject_type', 'server')->where('subject_id', $this->server->id)->count());
        $this->assertSame('pending', $this->server->fresh()->displayStatus());
    }

    public function test_after_the_first_sample_normal_offline_detection_applies(): void
    {
        $this->withToken($this->agentToken)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 2])->assertCreated();
        $this->assertSame('online', $this->server->fresh()->displayStatus());

        $this->travel(10)->minutes();
        $this->artisan('serverscope:check-agents')->assertSuccessful();

        $this->assertSame('offline', $this->server->fresh()->displayStatus());
        $this->assertSame(1, Alert::withoutGlobalScopes()->where('server_id', $this->server->id)->where('metric', 'agent_offline')->count());
    }
}
