<?php

namespace Tests\Feature\Enrollment;

use App\Models\AuditLog;
use App\Models\EnrollmentToken;
use App\Services\EnrollmentService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Creating, listing and revoking enrollment tokens in the dashboard.
 */
class EnrollmentTokenManagementTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config(['app.url' => 'https://monitor.example.test']);
        $this->createTwoTeams();
    }

    private function createToken(array $input = []): string
    {
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'), $input)->assertRedirect(route('servers.add'));

        return (string) session('enrollment_token');
    }

    public function test_an_admin_creates_a_one_time_hashed_token_for_their_team(): void
    {
        $plain = $this->createToken(['label' => 'web-01']);

        $this->assertMatchesRegularExpression('/\Asse_[a-f0-9]{48}\z/', $plain);

        $token = EnrollmentToken::withoutGlobalScopes()->sole();
        $this->assertSame($this->a['team']->id, $token->team_id);
        $this->assertSame($this->a['admin']->id, $token->created_by);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($token->getAttributes()));
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->assertNull($token->used_at);

        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('team_id', $this->a['team']->id)->where('action', 'enrollment.created')->exists());

        // The token is shown once: a later visit no longer contains it.
        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertOk()->assertDontSee($plain);
    }

    public function test_the_page_shows_the_command_the_token_and_a_root_warning(): void
    {
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'));
        $page = $this->actingAs($this->a['admin'])->followingRedirects()->get(route('servers.add'));

        // The token is flashed for this one visit.
        $plain = (string) session('enrollment_token');
        $page->assertOk()
            ->assertSee("curl --proto '=https' --tlsv1.2 -fsSL https://monitor.example.test/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh")
            ->assertSee($plain)
            ->assertSee('als root')
            ->assertSee(route('agent.install-script'))
            ->assertSee('Wachten op agent');

        $this->assertStringNotContainsString('bash -s', $page->getContent());
        // The command never contains the token.
        $this->assertDoesNotMatchRegularExpression('/serverscope-install\.sh[^<]*sse_/', $page->getContent());
    }

    public function test_members_cannot_create_or_revoke_tokens(): void
    {
        $this->actingAs($this->a['member'])->post(route('enrollment-tokens.store'))->assertForbidden();
        $this->assertSame(0, EnrollmentToken::withoutGlobalScopes()->count());

        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'));
        $token = EnrollmentToken::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['member'])->delete(route('enrollment-tokens.destroy', $token))->assertForbidden();

        // Members see the explanation but no way to create a token.
        $this->actingAs($this->a['member'])->get(route('servers.add'))->assertOk()->assertDontSee(route('enrollment-tokens.store'));
    }

    public function test_tokens_of_another_team_do_not_exist(): void
    {
        $this->actingAs($this->b['admin'])->post(route('enrollment-tokens.store'), ['label' => 'bravo-geheim']);
        $foreign = EnrollmentToken::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['admin'])->delete(route('enrollment-tokens.destroy', $foreign))->assertNotFound();
        $this->actingAs($this->a['admin'])->getJson(route('enrollment-tokens.status', $foreign))->assertNotFound();
        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertOk()->assertDontSee('bravo-geheim')->assertDontSee($foreign->prefix);
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_pending_tokens_are_limited_per_team(): void
    {
        config(['serverscope.enrollment.max_pending' => 2]);

        $this->createToken();
        $this->createToken();
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'))->assertSessionHas('error');
        $this->assertSame(2, EnrollmentToken::withoutGlobalScopes()->where('team_id', $this->a['team']->id)->count());

        // Another team has its own limit.
        $this->actingAs($this->b['admin'])->post(route('enrollment-tokens.store'))->assertSessionHas('enrollment_token');

        // Expired, used or revoked tokens do not count.
        EnrollmentToken::withoutGlobalScopes()->where('team_id', $this->a['team']->id)->first()->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'))->assertSessionHas('enrollment_token');
    }

    public function test_revoking_a_token_is_audited_and_makes_it_unusable(): void
    {
        $plain = $this->createToken();
        $token = EnrollmentToken::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['admin'])->delete(route('enrollment-tokens.destroy', $token))->assertSessionHas('success');

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame($this->a['admin']->id, $token->fresh()->revoked_by);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'enrollment.revoked')->exists());
        $this->postJson(route('agent.enroll'), ['token' => $plain, 'hostname' => 'web-01', 'os' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '0.2.0'])->assertUnauthorized();

        // Revoked tokens no longer show up as pending.
        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertDontSee($token->prefix);
    }

    public function test_live_status_moves_from_waiting_to_connected(): void
    {
        $plain = $this->createToken();
        $token = EnrollmentToken::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['admin'])->getJson(route('enrollment-tokens.status', $token))->assertOk()->assertJsonPath('state', 'waiting');

        $result = app(EnrollmentService::class)->redeem($plain, ['hostname' => 'web-01', 'os' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '0.2.0'], '203.0.113.5');
        $this->actingAs($this->a['admin'])->getJson(route('enrollment-tokens.status', $token))->assertJsonPath('state', 'enrolled');

        // Connected once the first sample arrived.
        $this->withToken($result['agent_token'])->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 5])->assertCreated();
        $this->actingAs($this->a['admin'])->getJson(route('enrollment-tokens.status', $token))
            ->assertJsonPath('state', 'connected')
            ->assertJsonPath('server_url', route('servers.show', $result['server']));

        $token->forceFill(['used_at' => null, 'server_id' => null, 'expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->a['admin'])->getJson(route('enrollment-tokens.status', $token))->assertJsonPath('state', 'expired');
    }

    public function test_an_http_app_url_shows_an_error_instead_of_an_unsafe_command(): void
    {
        config(['app.url' => 'http://monitor.example.test', 'serverscope.enrollment.allow_insecure_url' => false]);

        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertOk()
            ->assertSee('APP_URL')
            ->assertDontSee('serverscope-install.sh');
        $this->actingAs($this->a['admin'])->post(route('enrollment-tokens.store'))->assertSessionHas('error');
        $this->assertSame(0, EnrollmentToken::withoutGlobalScopes()->count());

        // Only an explicit setting allows it (local testing).
        config(['serverscope.enrollment.allow_insecure_url' => true]);
        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertSee('serverscope-install.sh');
    }

    public function test_the_manual_registration_still_works(): void
    {
        $this->actingAs($this->a['admin'])->get(route('servers.create'))->assertOk();
        $this->actingAs($this->a['admin'])->get(route('servers.add'))->assertOk()->assertSee(route('servers.create'));
    }

    public function test_tokens_can_be_created_by_the_service_only_with_a_team(): void
    {
        [$token, $plain] = Tenant::run($this->b['team'], fn () => app(EnrollmentService::class)->create($this->b['admin'], 'x'));

        $this->assertSame($this->b['team']->id, $token->team_id);
        $this->assertStringStartsWith('sse_', $plain);
    }
}
