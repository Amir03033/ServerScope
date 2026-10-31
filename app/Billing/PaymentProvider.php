<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * What a payment provider (Mollie, Stripe, ...) must do. A driver is added
 * in App\Billing\Providers and registered in BillingManager::DRIVERS.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Origins the browser is sent to for checkout (allowed as form target
     * on the billing page only), like https://www.mollie.com.
     *
     * @return array<int, string>
     */
    public function checkoutOrigins(): array;

    /** Hosted checkout URL; no card or bank data ever touches this app. */
    public function checkoutUrl(Team $team, string $plan, string $interval, string $returnUrl): string;

    /**
     * Verify the signature and translate the request. Null = not an event
     * we act on. Must throw (or return null) for an invalid signature.
     */
    public function parseWebhook(Request $request): ?BillingEvent;

    /** Ask the provider to stop renewing at the end of the period. */
    public function cancel(Subscription $subscription): void;
}
