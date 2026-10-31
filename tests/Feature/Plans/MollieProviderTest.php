<?php

namespace Tests\Feature\Plans;

use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Mollie driver against a faked Mollie API (no real Mollie was contacted). */
class MollieProviderTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return hash_hmac('sha256', 'mollie-webhook', 'secret-123');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'serverscope.billing.enabled' => true, 'serverscope.billing.driver' => 'mollie',
            'serverscope.billing.api_key' => 'test_abc', 'serverscope.billing.webhook_secret' => 'secret-123',
            'serverscope.billing.test_mode' => true,
            'serverscope.billing.prices.pro.month' => '19.00', 'serverscope.billing.prices.pro.year' => '190.00',
        ]);
    }

    private function owner(Team $team): User
    {
        $user = User::factory()->create();
        $user->teams()->attach($team->id, ['role' => 'owner']);
        $user->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();

        return $user;
    }

    public function test_checkout_creates_customer_and_first_payment_and_redirects_to_mollie(): void
    {
        Http::fake([
            'api.mollie.com/v2/customers' => Http::response(['id' => 'cst_1'], 201),
            'api.mollie.com/v2/payments' => Http::response(['id' => 'tr_1', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/x']]], 201),
        ]);
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        $user = $this->owner($team);

        $this->actingAs($user)->post(route('billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])->assertRedirect('https://www.mollie.com/checkout/x');

        $this->assertSame('cst_1', $team->fresh()->billing_customer_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payments')
            && $r['sequenceType'] === 'first' && $r['amount'] === ['currency' => 'EUR', 'value' => '19.00']
            && $r['customerId'] === 'cst_1' && $r['metadata']['team_id'] === $team->id
            && str_contains($r['webhookUrl'], 't='.$this->token()));
        $this->assertSame('pending_payment', $team->fresh()->plan_status);
    }

    public function test_paid_first_payment_activates_and_creates_the_subscription_once(): void
    {
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        Http::fake([
            'api.mollie.com/v2/payments/tr_paid9' => Http::response(['id' => 'tr_paid9', 'status' => 'paid', 'sequenceType' => 'first', 'customerId' => 'cst_1',
                'amount' => ['currency' => 'EUR', 'value' => '19.00'], 'metadata' => ['team_id' => $team->id, 'plan' => 'pro', 'interval' => 'month']]),
            'api.mollie.com/v2/customers/cst_1/subscriptions' => Http::sequence()
                ->push(['_embedded' => ['subscriptions' => []]])
                ->push(['id' => 'sub_1'], 201)
                ->push(['_embedded' => ['subscriptions' => [['id' => 'sub_1', 'status' => 'active', 'metadata' => ['plan' => 'pro', 'interval' => 'month']]]]]),
        ]);

        $url = '/api/webhooks/payments?t='.$this->token();
        $this->post($url, ['id' => 'tr_paid9'])->assertOk();
        $this->post($url, ['id' => 'tr_paid9'])->assertOk(); // Mollie retries / replays

        $this->assertSame(['pro', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
        $sub = Subscription::query()->sole();
        $this->assertSame(['mollie', 'sub_1', 'cst_1'], [$sub->provider, $sub->provider_subscription_id, $sub->provider_customer_id]);
        $this->assertTrue($sub->current_period_end->isFuture());
    }

    public function test_wrong_token_or_bad_id_is_rejected_and_amount_mismatch_grants_nothing(): void
    {
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        Http::fake(['api.mollie.com/v2/payments/tr_wrong5' => Http::response(['id' => 'tr_wrong5', 'status' => 'paid', 'sequenceType' => 'first', 'customerId' => 'cst_1',
            'amount' => ['currency' => 'EUR', 'value' => '1.00'], 'metadata' => ['team_id' => $team->id, 'plan' => 'pro', 'interval' => 'month']])]);

        $this->post('/api/webhooks/payments?t=wrong', ['id' => 'tr_wrong5'])->assertStatus(400);
        $this->post('/api/webhooks/payments?t='.$this->token(), ['id' => '../x'])->assertStatus(400);
        $this->post('/api/webhooks/payments?t='.$this->token(), ['id' => 'tr_wrong5'])->assertStatus(400);
        $this->assertSame('pending_payment', $team->fresh()->plan_status);
    }

    public function test_failed_renewal_starts_grace_but_failed_first_payment_changes_nothing(): void
    {
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'active']);
        Subscription::query()->create(['team_id' => $team->id, 'provider' => 'mollie', 'provider_subscription_id' => 'sub_1', 'provider_customer_id' => 'cst_1', 'plan' => 'pro', 'interval' => 'month', 'status' => 'active', 'current_period_end' => now()->addDays(3)]);
        Http::fake([
            'api.mollie.com/v2/payments/tr_renew' => Http::response(['id' => 'tr_renew', 'status' => 'failed', 'sequenceType' => 'recurring', 'subscriptionId' => 'sub_1', 'customerId' => 'cst_1', 'amount' => ['value' => '19.00']]),
            'api.mollie.com/v2/payments/tr_first' => Http::response(['id' => 'tr_first', 'status' => 'failed', 'sequenceType' => 'first', 'customerId' => 'cst_1', 'metadata' => ['team_id' => $team->id, 'plan' => 'pro', 'interval' => 'month']]),
        ]);

        $this->post('/api/webhooks/payments?t='.$this->token(), ['id' => 'tr_first'])->assertOk();
        $this->assertSame('active', Subscription::query()->sole()->status);

        $this->post('/api/webhooks/payments?t='.$this->token(), ['id' => 'tr_renew'])->assertOk();
        $this->assertSame('past_due', Subscription::query()->sole()->status);
        $this->assertSame('pro', $team->fresh()->effectivePlan());
    }

    public function test_cancel_deletes_at_mollie_and_downgrades_only_after_the_period(): void
    {
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'active']);
        $sub = Subscription::query()->create(['team_id' => $team->id, 'provider' => 'mollie', 'provider_subscription_id' => 'sub_1', 'provider_customer_id' => 'cst_1', 'plan' => 'pro', 'interval' => 'month', 'status' => 'active', 'current_period_end' => now()->addDays(10)]);
        Http::fake(['api.mollie.com/v2/customers/cst_1/subscriptions/sub_1' => Http::response(null, 204)]);

        $this->actingAs($this->owner($team))->post(route('billing.cancel'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->assertSame('pro', $team->fresh()->effectivePlan());

        $this->artisan('serverscope:billing:expire-grace')->assertSuccessful();
        $this->assertSame('pro', $team->fresh()->plan);

        $sub->update(['current_period_end' => now()->subDay()]);
        $this->artisan('serverscope:billing:expire-grace')->assertSuccessful();
        $this->assertSame(['free', 'active'], [$team->fresh()->plan, $team->fresh()->plan_status]);
    }

    public function test_key_must_match_test_mode_and_nothing_is_sent_when_misconfigured(): void
    {
        Http::fake();
        config(['serverscope.billing.test_mode' => false]); // key is test_...
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'pending_payment']);

        $this->actingAs($this->owner($team))->post(route('billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_a_month_from_the_31st_ends_on_the_last_day_of_the_next_month(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
        $team = Team::query()->create(['name' => 'M', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        Http::fake([
            'api.mollie.com/v2/payments/tr_date31' => Http::response(['id' => 'tr_date31', 'status' => 'paid', 'sequenceType' => 'first', 'customerId' => 'cst_1',
                'amount' => ['currency' => 'EUR', 'value' => '19.00'], 'metadata' => ['team_id' => $team->id, 'plan' => 'pro', 'interval' => 'month']]),
            'api.mollie.com/v2/customers/cst_1/subscriptions' => Http::sequence()->push(['_embedded' => ['subscriptions' => []]])->push(['id' => 'sub_1'], 201),
        ]);

        $this->post('/api/webhooks/payments?t='.$this->token(), ['id' => 'tr_date31'])->assertOk();

        $this->assertSame('2026-11-30', Subscription::query()->sole()->current_period_end->toDateString());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/subscriptions') && $r['startDate'] === '2026-11-30');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_billing_page_shows_the_price_next_to_each_option(): void
    {
        $team = Team::query()->create(['name' => 'P', 'plan' => 'pro', 'plan_status' => 'pending_payment']);
        config(['serverscope.billing.prices.premium.month' => '49.00', 'serverscope.billing.prices.premium.year' => '490.00']);

        $html = $this->actingAs($this->owner($team))->get(route('billing.show'))->assertOk()->getContent();

        // One card per plan; with two intervals each button carries its price.
        foreach (['€ 19,00', '€ 190,00', '€ 49,00', '€ 490,00'] as $price) {
            $this->assertStringContainsString($price, $html);
        }
        $this->assertSame(3, substr_count($html, 'data-testid="plan-card"'));
        $this->assertSame(3, substr_count($html, 'data-testid="price"'));
    }

    public function test_a_cancelled_subscription_says_it_ends_instead_of_renews(): void
    {
        $team = Team::query()->create(['name' => 'E', 'plan' => 'pro', 'plan_status' => 'active']);
        $sub = Subscription::query()->create(['team_id' => $team->id, 'provider' => 'mollie', 'provider_subscription_id' => 'sub_9', 'provider_customer_id' => 'cst_9', 'plan' => 'pro', 'interval' => 'month', 'status' => 'active', 'current_period_end' => '2026-11-30 12:00:00']);
        $user = $this->owner($team);

        $this->actingAs($user)->get(route('billing.show'))->assertSee('Verlengt op 2026-11-30')->assertDontSee('Eindigt op 2026-11-30');

        $sub->update(['cancel_at_period_end' => true]);
        $this->actingAs($user)->get(route('billing.show'))->assertSee('Eindigt op 2026-11-30 (opgezegd)')->assertDontSee('Verlengt op');
    }
}
