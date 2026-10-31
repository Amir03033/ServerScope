<?php

namespace Tests\Feature\Plans;

use App\Billing\BillingEvent;
use App\Billing\BillingManager;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function pay(Team $team, string $payment, int $cents = 1900): void
    {
        app(BillingManager::class)->apply('mollie', new BillingEvent("{$payment}:paid", BillingEvent::PAID, $team->id, 'pro', 'month', 'cst_1', 'sub_1', now()->addMonth(), $payment, $cents));
    }

    private function company(bool $vat = true): void
    {
        config(['serverscope.billing.company' => ['name' => 'Voorbeeld BV', 'kvk' => '12345678', 'address' => "Straat 1\n1234 AB Plaats", 'email' => 'a@example.test', 'vat_id' => $vat ? 'NL123456789B01' : null]]);
    }

    public function test_payment_without_seller_details_is_a_draft_until_they_are_filled_in(): void
    {
        $team = Team::query()->create(['name' => 'T']);
        $this->pay($team, 'tr_one11');

        $invoice = Invoice::query()->sole();
        $this->assertNull($invoice->number);
        $this->artisan('serverscope:invoices:issue')->assertSuccessful();
        $this->assertNull($invoice->fresh()->number);

        $this->company();
        $this->artisan('serverscope:invoices:issue')->assertSuccessful();
        $this->assertSame(now()->year.'-0001', $invoice->fresh()->number);
        $this->assertSame('Voorbeeld BV', $invoice->fresh()->seller['name']);
    }

    public function test_numbers_are_gapless_payments_idempotent_and_vat_is_split(): void
    {
        $this->company();
        $team = Team::query()->create(['name' => 'T']);
        $this->pay($team, 'tr_one11');
        $this->pay($team, 'tr_one11'); // replay
        $this->pay($team, 'tr_two22');

        $numbers = Invoice::query()->orderBy('id')->pluck('number')->all();
        $this->assertSame([now()->year.'-0001', now()->year.'-0002'], $numbers);
        $first = Invoice::query()->orderBy('id')->first();
        $this->assertSame([1900, 1570, 330], [$first->amount_incl, $first->amount_excl, $first->vat_amount]);
    }

    public function test_without_vat_number_no_vat_is_charged_and_the_note_is_printed(): void
    {
        $this->company(vat: false);
        config(['serverscope.billing.vat_note' => 'KOR-tekst']);
        $team = Team::query()->create(['name' => 'T']);
        $this->pay($team, 'tr_kor11');

        $i = Invoice::query()->sole();
        $this->assertSame([1900, 1900, 0], [$i->amount_incl, $i->amount_excl, $i->vat_amount]);
        $this->assertSame('KOR-tekst', $i->vat_note);
    }

    public function test_only_the_own_team_sees_an_issued_invoice(): void
    {
        $this->company();
        $a = Team::query()->create(['name' => 'A']);
        $b = Team::query()->create(['name' => 'B']);
        $this->pay($a, 'tr_aaa11');
        $invoice = Invoice::query()->sole();

        $mk = function (Team $t) {
            $u = User::factory()->create();
            $u->teams()->attach($t->id, ['role' => 'owner']);
            $u->forceFill(['current_team_id' => $t->id, 'is_admin' => true])->save();

            return $u;
        };

        $this->actingAs($mk($a))->get(route('billing.invoice', $invoice))->assertOk()->assertSee('Voorbeeld BV')->assertSee($invoice->number);
        $this->actingAs($mk($b))->get(route('billing.invoice', $invoice))->assertNotFound();
        $this->actingAs($mk($a))->get(route('billing.invoices'))->assertOk();
        $this->actingAs($mk($a))->put(route('billing.details'), ['name' => 'Acme', 'address' => 'Straat 2', 'country' => 'nl'])->assertSessionHasNoErrors();
        $this->assertSame('NL', $a->fresh()->billing_details['country']);
    }
}
