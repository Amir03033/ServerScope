<?php

namespace App\Billing;

use App\Billing\Providers\NullProvider;
use App\Models\Subscription;
use App\Models\Team;
use App\Support\Audit;
use App\Support\PlanLimits;
use App\Tenancy\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Billing entry point. Only a verified provider webhook (or an operator
 * via serverscope:team:plan) can make a paid plan active; returning from
 * checkout never does.
 */
class BillingManager
{
    /** driver name => provider class. Add Mollie/Stripe here once chosen. */
    public const DRIVERS = [
        'none' => NullProvider::class,
        'mollie' => \App\Billing\Providers\MollieProvider::class,
    ];

    public function enabled(): bool
    {
        return (bool) config('serverscope.billing.enabled') && $this->provider()->name() !== 'none';
    }

    public function provider(): PaymentProvider
    {
        $class = self::DRIVERS[(string) config('serverscope.billing.driver', 'none')] ?? NullProvider::class;

        return app($class);
    }

    /** Price id configured for a plan and interval, or null. */
    public function priceId(string $plan, string $interval): ?string
    {
        $id = config("serverscope.billing.prices.{$plan}.{$interval}");

        return filled($id) ? (string) $id : null;
    }

    public function canCheckout(string $plan, string $interval): bool
    {
        return $this->enabled() && in_array($plan, ['pro', 'premium'], true)
            && in_array($interval, ['month', 'year'], true) && $this->priceId($plan, $interval) !== null;
    }

    /** Apply a verified event. Returns false when it was already processed. */
    public function apply(string $provider, BillingEvent $event): bool
    {
        $team = Team::query()->find($event->teamId);

        if ($team === null || ! in_array($event->plan, ['pro', 'premium'], true)) {
            return true; // Nothing to do; acknowledge so the provider stops retrying.
        }

        try {
            DB::transaction(function () use ($provider, $event, $team): void {
                DB::table('billing_events')->insert(['provider' => $provider, 'event_id' => $event->id, 'type' => $event->type, 'processed_at' => now()]);

                $sub = Subscription::query()->firstOrNew(['team_id' => $team->id, 'provider' => $provider, 'provider_subscription_id' => $event->subscriptionId]);
                $sub->fill([
                    'provider_customer_id' => $event->customerId ?? $sub->provider_customer_id,
                    'plan' => $event->plan,
                    'interval' => $event->interval,
                    'current_period_end' => $event->periodEnd ?? $sub->current_period_end,
                ]);

                match ($event->type) {
                    BillingEvent::PAID => $this->activate($team, $sub, $event, $provider),
                    BillingEvent::PAYMENT_FAILED => $this->pastDue($team, $sub),
                    BillingEvent::CANCELED => $this->cancel($team, $sub),
                    default => null,
                };
            });
        } catch (UniqueConstraintViolationException) {
            return false; // Same event delivered twice.
        }

        return true;
    }

    private function activate(Team $team, Subscription $sub, BillingEvent $event, string $provider): void
    {
        $sub->fill(['status' => 'active', 'cancel_at_period_end' => false])->save();
        app(InvoiceService::class)->recordPayment($team, $event, $provider);
        $team->forceFill(['plan' => $sub->plan, 'plan_status' => PlanLimits::ACTIVE])->save();
        Tenant::run($team, fn () => Audit::log('billing.paid', "Betaling ontvangen, plan {$sub->plan} actief"));
    }

    private function pastDue(Team $team, Subscription $sub): void
    {
        // The paid plan keeps working for the grace period (see ExpireBillingGrace).
        $sub->fill(['status' => 'past_due'])->save();
        Tenant::run($team, fn () => Audit::log('billing.payment_failed', "Betaling mislukt voor plan {$sub->plan}"));
    }

    private function cancel(Team $team, Subscription $sub): void
    {
        $sub->fill(['status' => 'canceled'])->save();
        $this->downgrade($team);
        Tenant::run($team, fn () => Audit::log('billing.canceled', 'Abonnement beëindigd, terug naar Free (gegevens blijven)'));
    }

    /** Back to Free/active. Data stays; only the limits change. */
    public function downgrade(Team $team): void
    {
        $team->forceFill(['plan' => PlanLimits::FREE, 'plan_status' => PlanLimits::ACTIVE])->save();
    }

    /** Teams whose failed payment outlived the grace period go back to Free. */
    public function expireGrace(): int
    {
        $cutoff = now()->subDays((int) config('serverscope.billing.grace_days', 7));
        $count = 0;

        Subscription::query()->where('status', 'past_due')->where('updated_at', '<', $cutoff)->get()->each(function (Subscription $sub) use (&$count): void {
            $sub->update(['status' => 'canceled']);
            if ($sub->team) {
                $this->downgrade($sub->team);
                $count++;
            }
        });

        // Cancelled subscriptions end when the paid period is over.
        Subscription::query()->where('status', 'active')->where('cancel_at_period_end', true)->where('current_period_end', '<', now())->get()->each(function (Subscription $sub) use (&$count): void {
            $sub->update(['status' => 'canceled']);
            if ($sub->team) {
                $this->downgrade($sub->team);
                $count++;
            }
        });

        return $count;
    }
}
