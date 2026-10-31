<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One invoice per successful payment. Created as a draft at payment time;
 * issued (numbered, seller and buyer frozen) only once the seller details
 * from .env are complete, so no invoice is ever issued without them.
 *
 * Prices are final amounts. With a btw number configured the amount is
 * treated as including the configured rate; without one no btw is
 * charged and the note (SERVERSCOPE_VAT_NOTE) is printed. Not handled:
 * reverse charge for EU businesses outside NL and non-EU customers
 * (ask your accountant before selling there).
 */
class InvoiceService
{
    public function sellerComplete(): bool
    {
        $c = (array) config('serverscope.billing.company');

        return filled($c['name'] ?? null) && filled($c['kvk'] ?? null) && filled($c['address'] ?? null) && filled($c['email'] ?? null);
    }

    public function vatRate(): float
    {
        return filled(config('serverscope.billing.company.vat_id')) ? (float) config('serverscope.billing.vat_rate', 21) : 0.0;
    }

    /** Idempotent on the provider payment id. */
    public function recordPayment(Team $team, BillingEvent $event, string $provider): ?Invoice
    {
        if ($event->paymentId === null || $event->amountCents === null) {
            return null;
        }

        $rate = $this->vatRate();
        $incl = $event->amountCents;
        $vat = $rate > 0 ? (int) round($incl * $rate / (100 + $rate)) : 0;

        try {
            $invoice = Invoice::query()->create([
                'team_id' => $team->id, 'provider' => $provider, 'provider_payment_id' => $event->paymentId,
                'plan' => $event->plan, 'interval' => $event->interval, 'currency' => strtoupper((string) config('serverscope.billing.currency', 'EUR')),
                'amount_incl' => $incl, 'vat_rate' => $rate, 'vat_amount' => $vat, 'amount_excl' => $incl - $vat,
                'paid_at' => now(), 'period_start' => now(), 'period_end' => $event->periodEnd,
                'vat_note' => $rate > 0 ? null : config('serverscope.billing.vat_note'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->issue($invoice);

        return $invoice;
    }

    /** Number and freeze a draft, when the seller details are complete. */
    public function issue(Invoice $invoice): bool
    {
        if ($invoice->isIssued() || ! $this->sellerComplete()) {
            return false;
        }

        DB::transaction(function () use ($invoice): void {
            $year = now()->year;
            $last = Invoice::query()->where('number', 'like', "{$year}-%")->lockForUpdate()->max('number');
            $next = $last ? ((int) substr($last, 5)) + 1 : 1;
            $team = Team::query()->find($invoice->team_id);
            $details = (array) ($team?->billing_details ?? []);

            $invoice->update([
                'number' => sprintf('%d-%04d', $year, $next),
                'issued_at' => now(),
                'seller' => (array) config('serverscope.billing.company'),
                'buyer' => ['name' => $details['name'] ?? $team?->name, 'address' => $details['address'] ?? null, 'country' => $details['country'] ?? null, 'vat_id' => $details['vat_id'] ?? null],
            ]);
        });

        return true;
    }

    public function issueDrafts(): int
    {
        $n = 0;

        foreach (Invoice::query()->whereNull('number')->orderBy('id')->get() as $invoice) {
            $n += (int) $this->issue($invoice);
        }

        return $n;
    }
}
