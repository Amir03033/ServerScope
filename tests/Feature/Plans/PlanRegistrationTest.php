<?php

namespace Tests\Feature\Plans;

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\PlanLimits;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Test User', 'team' => 'Acme', 'email' => 'new@example.test',
            'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x',
        ], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverscope.tenancy.registration_enabled' => true]);
    }

    public function test_registration_is_404_when_disabled(): void
    {
        config(['serverscope.tenancy.registration_enabled' => false]);
        $this->get('/register?plan=pro')->assertNotFound();
    }

    public function test_plan_is_whitelisted(): void
    {
        foreach (['free', 'pro', 'premium'] as $plan) {
            $this->get("/register?plan={$plan}")->assertOk()->assertSee('name="plan" value="'.$plan.'"', false);
        }
        foreach (['ultra', 'enterprise', '', '<script>'] as $bad) {
            $this->get('/register?plan='.urlencode($bad))->assertOk()->assertSee('name="plan" value="free"', false);
        }
        $this->assertSame('free', PlanLimits::normalize(['pro']));
        $this->assertSame('pro', PlanLimits::normalize(' PRO '));
    }

    public function test_free_is_active_immediately(): void
    {
        $this->post('/register', $this->payload(['plan' => 'free']))->assertRedirect(route('register.sent'));
        $team = Team::query()->where('name', 'Acme')->sole();
        $this->assertSame(['free', 'active'], [$team->plan, $team->plan_status]);
        $this->assertFalse($team->isPendingPayment());
    }

    public function test_paid_plans_are_pending_and_unknown_plan_becomes_free(): void
    {
        foreach (['pro', 'premium'] as $i => $plan) {
            $this->app['auth']->forgetGuards();
            $this->post('/register', $this->payload(['plan' => $plan, 'team' => "T{$i}", 'email' => "u{$i}@example.test"]));
            $team = Team::query()->where('name', "T{$i}")->sole();
            $this->assertSame([$plan, 'pending_payment'], [$team->plan, $team->plan_status]);
            $this->assertSame('free', $team->effectivePlan());
            $this->actingAs(User::query()->where('email', "u{$i}@example.test")->sole()->forceFill(['email_verified_at' => now()]))
                ->get('/dashboard')->assertSee('betaling volgt', false) ;
        }

        $this->app['auth']->forgetGuards();
        $this->post('/register', $this->payload(['plan' => 'ultra', 'team' => 'Odd', 'email' => 'odd@example.test']));
        $odd = Team::query()->where('name', 'Odd')->sole();
        $this->assertSame(['free', 'active'], [$odd->plan, $odd->plan_status]);
    }

    public function test_pending_team_gets_free_limits_until_active(): void
    {
        config(['serverscope.plans.free.limits.servers' => 1, 'serverscope.plans.pro.limits.servers' => 5]);
        $team = Team::query()->create(['name' => 'P', 'plan' => 'pro', 'plan_status' => 'pending_payment']);

        Tenant::run($team, function () use ($team) {
            $this->assertSame(1, PlanLimits::limit('servers'));
            $this->assertFalse(PlanLimits::allows('api_tokens'));
            $team->update(['plan_status' => 'active']);
            $this->assertSame(5, PlanLimits::limit('servers'));
            $this->assertTrue(PlanLimits::allows('api_tokens'));
        });
    }

    public function test_server_limit_blocks_creation_for_free_team(): void
    {
        config(['serverscope.plans.free.limits.servers' => 1]);
        $team = Team::query()->create(['name' => 'Lim', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $user = User::factory()->create();
        $user->teams()->attach($team->id, ['role' => 'owner']);
        $user->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();

        $form = ['name' => 'a', 'hostname' => 'a.example.test', 'environment' => 'production', 'credential_name' => 'x'];
        $this->actingAs($user)->post(route('servers.store'), $form)->assertSessionHas('agent_token');
        $this->actingAs($user)->post(route('servers.store'), [...$form, 'name' => 'b', 'hostname' => 'b.example.test'])->assertSessionHas('error');
        Tenant::run($team, fn () => $this->assertSame(1, Server::query()->count()));
    }

    public function test_default_team_is_exempt_and_existing_teams_are_free_active(): void
    {
        $default = Team::defaultTeam();
        if ($default) {
            $this->assertNull(PlanLimits::limit('servers', $default));
        }
        $team = Team::query()->create(['name' => 'Old']);
        $this->assertSame(['free', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
    }

    public function test_prune_applies_plan_retention_per_team(): void
    {
        config(['serverscope.plans.free.limits.retention_days' => 30]);
        $team = Team::query()->create(['name' => 'Ret', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $server = Tenant::run($team, fn () => Server::query()->create(['name' => 's', 'hostname' => 's.example.test', 'status' => 'pending']));
        foreach ([60, 5] as $days) {
            \Illuminate\Support\Facades\DB::table('metric_samples')->insert([
                'server_id' => $server->id, 'collected_at' => now()->subDays($days), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('serverscope:prune')->assertSuccessful();

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('metric_samples')->count());
    }

    public function test_over_limit_notice_after_downgrade(): void
    {
        config(['serverscope.plans.free.limits.servers' => 1]);
        $team = Team::query()->create(['name' => 'Over', 'plan' => 'free', 'plan_status' => 'active']);
        Tenant::run($team, function () {
            Server::query()->create(['name' => 'a', 'hostname' => 'a.example.test', 'status' => 'pending']);
            Server::query()->create(['name' => 'b', 'hostname' => 'b.example.test', 'status' => 'pending']);
        });
        $user = User::factory()->create();
        $user->teams()->attach($team->id, ['role' => 'owner']);
        $user->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();

        $this->actingAs($user)->get('/dashboard')->assertSee('data-testid="plan-over-limit"', false)->assertSee('2/1');
        $this->assertSame(['servers' => ['count' => 2, 'limit' => 1]], Tenant::run($team, fn () => PlanLimits::overLimit($team)));
    }
}
