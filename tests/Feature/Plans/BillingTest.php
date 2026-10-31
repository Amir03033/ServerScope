<?php

namespace Tests\Feature\Plans;

use App\Billing\BillingEvent;
use App\Billing\BillingManager;
use App\Billing\PaymentProvider;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(?BillingEvent $event): void
    {
        $provider = new class($event) implements PaymentProvider {
            public array $canceled = [];
            public function __construct(public ?BillingEvent $event) {}
            public function name(): string { return 'fake'; }
            public function checkoutUrl(Team $team, string $plan, string $interval, string $returnUrl): string { return 'https://pay.example.test/c/'.$plan; }
            public function parseWebhook(Request $request): ?BillingEvent { if ($request->header('X-Sig') !== 'ok') { throw new \RuntimeException('bad'); } return $this->event; }
            public function cancel(Subscription $subscription): void { $this->canceled[] = $subscription->id; }
            public function checkoutOrigins(): array { return ['https://pay.example.test']; }
        };
        $this->app->bind(\App\Billing\Providers\NullProvider::class, fn () => $provider);
        config(['serverscope.billing.enabled' => true, 'serverscope.billing.driver' => 'none']);
    }

    private function enableFake(?BillingEvent $event): PaymentProvider
    {
        $this->fakeProvider($event);

        return app(\App\Billing\Providers\NullProvider::class);
    }

    public function test_billing_is_off_by_default_webhook_404_and_no_checkout(): void
    {
        $this->postJson('/api/webhooks/payments', [])->assertNotFound();
        $this->assertFalse(app(BillingManager::class)->enabled());
        $this->assertFalse(app(BillingManager::class)->canCheckout('pro', 'month'));
    }

    public function test_verified_paid_event_activates_the_plan_once(): void
    {
        $team = Team::query()->create(['name' => 'P', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $this->enableFake(new BillingEvent('evt_1', BillingEvent::PAID, $team->id, 'pro', 'month', 'cus_1', 'sub_1'));

        $this->postJson('/api/webhooks/payments', [])->assertStatus(400); // no valid signature
        $this->assertSame('pending_payment', $team->fresh()->plan_status);

        $this->postJson('/api/webhooks/payments', [], ['X-Sig' => 'ok'])->assertOk();
        $this->postJson('/api/webhooks/payments', [], ['X-Sig' => 'ok'])->assertOk(); // replay
        $this->assertSame(['pro', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_failed_payment_keeps_plan_during_grace_then_returns_to_free(): void
    {
        $team = Team::query()->create(['name' => 'G', 'plan' => 'pro', 'plan_status' => 'active']);
        $this->enableFake(new BillingEvent('evt_2', BillingEvent::PAYMENT_FAILED, $team->id, 'pro', 'month', 'cus_2', 'sub_2'));
        $this->postJson('/api/webhooks/payments', [], ['X-Sig' => 'ok'])->assertOk();

        $this->assertSame('past_due', Subscription::query()->sole()->status);
        $this->assertSame('pro', $team->fresh()->effectivePlan());

        $this->assertSame(0, app(BillingManager::class)->expireGrace());
        Subscription::query()->update(['updated_at' => now()->subDays(8)]);
        $this->assertSame(1, app(BillingManager::class)->expireGrace());
        $this->assertSame(['free', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
    }

    public function test_cancellation_downgrades_to_free_and_keeps_data(): void
    {
        $team = Team::query()->create(['name' => 'C', 'plan' => 'premium', 'plan_status' => 'active']);
        $this->enableFake(new BillingEvent('evt_3', BillingEvent::CANCELED, $team->id, 'premium', 'year', 'cus_3', 'sub_3'));
        $this->postJson('/api/webhooks/payments', [], ['X-Sig' => 'ok'])->assertOk();
        $this->assertSame(['free', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
    }

    public function test_checkout_needs_a_configured_price_and_never_activates(): void
    {
        $team = Team::query()->create(['name' => 'K', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $user = User::factory()->create();
        $user->teams()->attach($team->id, ['role' => 'owner']);
        $user->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();
        $this->enableFake(null);

        $this->actingAs($user)->post(route('billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])->assertSessionHas('error'); // no price id yet

        config(['serverscope.billing.prices.pro.month' => 'price_x']);
        $this->actingAs($user)->post(route('billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])->assertRedirect('https://pay.example.test/c/pro');
        $this->assertSame('pending_payment', $team->fresh()->plan_status);
        $this->actingAs($user)->get(route('billing.show'))->assertOk();
    }

    public function test_operator_command_sets_a_plan(): void
    {
        $team = Team::query()->create(['name' => 'Op']);
        $this->artisan('serverscope:team:plan', ['team' => $team->slug, 'plan' => 'pro'])->assertSuccessful();
        $this->assertSame(['pro', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
        $this->artisan('serverscope:team:plan', ['team' => $team->id, 'plan' => 'bogus'])->assertFailed();
    }

    public function test_billing_has_a_menu_item_for_admins_only(): void
    {
        $team = Team::query()->create(['name' => 'Nav']);
        $mk = function (string $role) use ($team) {
            $u = User::factory()->create();
            $u->teams()->attach($team->id, ['role' => $role]);
            $u->forceFill(['current_team_id' => $team->id, 'is_admin' => $role !== 'member'])->save();

            return $u;
        };

        $this->actingAs($mk('owner'))->get('/dashboard')->assertSee(route('billing.show'), false);
        $this->actingAs($mk('member'))->get('/dashboard')->assertDontSee(route('billing.show'), false);
    }

    public function test_only_the_billing_page_allows_the_provider_as_form_target(): void
    {
        $team = Team::query()->create(['name' => 'Csp', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $u = User::factory()->create();
        $u->teams()->attach($team->id, ['role' => 'owner']);
        $u->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();
        $this->enableFake(null);

        $billingPage = $this->actingAs($u)->get(route('billing.show'))->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self' https://pay.example.test;", $billingPage);
        $other = $this->actingAs($u)->get('/dashboard')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self';", $other);
        $this->assertStringNotContainsString('pay.example.test', $other);
    }
}
