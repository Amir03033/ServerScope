<?php

namespace App\Billing\Providers;

use App\Billing\BillingEvent;
use App\Billing\PaymentProvider;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mollie (https://docs.mollie.com). Flow:
 *  1. checkout: a "first" payment (iDEAL/card) for the customer of the team;
 *  2. webhook for that payment: once it is paid, a Mollie subscription is
 *     created that renews by SEPA direct debit (starting one period later);
 *  3. every renewal is a payment with its own webhook.
 *
 * Mollie webhooks carry only a payment id and no signature. They are
 * trusted only because the payment is fetched again from the Mollie API
 * with our key; the webhook URL additionally carries a secret token.
 * The "price" settings (SERVERSCOPE_PRICE_*) hold the amount to charge,
 * like 19.00 (the final amount, including btw if that applies).
 */
class MollieProvider implements PaymentProvider
{
    private const BASE = 'https://api.mollie.com/v2';

    public function name(): string
    {
        return 'mollie';
    }

    public function checkoutOrigins(): array
    {
        return ['https://www.mollie.com', 'https://*.mollie.com'];
    }

    public function checkoutUrl(Team $team, string $plan, string $interval, string $returnUrl): string
    {
        $this->assertConfigured();
        $amount = $this->amount($plan, $interval);

        $payment = $this->api()->post('/payments', [
            'amount' => ['currency' => $this->currency(), 'value' => $amount],
            'description' => "ServerScope {$plan} ({$interval})",
            'redirectUrl' => $returnUrl,
            'webhookUrl' => $this->webhookUrl(),
            'customerId' => $this->customerFor($team),
            'sequenceType' => 'first',
            'metadata' => ['team_id' => $team->id, 'plan' => $plan, 'interval' => $interval],
        ])->throw()->json();

        $url = $payment['_links']['checkout']['href'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Mollie returned no checkout URL.');
        }

        return $url;
    }

    public function parseWebhook(Request $request): ?BillingEvent
    {
        $this->assertConfigured();

        if (! hash_equals($this->token(), (string) $request->query('t', ''))) {
            throw new RuntimeException('Invalid webhook token.');
        }

        $id = (string) $request->input('id', '');

        if (preg_match('/\Atr_[A-Za-z0-9]{3,40}\z/', $id) !== 1) {
            throw new RuntimeException('Invalid payment id.');
        }

        // The truth comes from Mollie, never from the request body.
        $payment = $this->api()->get("/payments/{$id}")->throw()->json();
        $status = (string) ($payment['status'] ?? '');
        $first = ($payment['sequenceType'] ?? '') === 'first';
        $subscriptionId = $payment['subscriptionId'] ?? null;

        $local = $subscriptionId ? Subscription::query()->where('provider', 'mollie')->where('provider_subscription_id', $subscriptionId)->first() : null;
        $meta = (array) ($payment['metadata'] ?? []);
        $teamId = (int) ($meta['team_id'] ?? $local?->team_id ?? 0);
        $plan = (string) ($meta['plan'] ?? $local?->plan ?? '');
        $interval = ($meta['interval'] ?? $local?->interval ?? 'month') === 'year' ? 'year' : 'month';
        $customerId = $payment['customerId'] ?? null;

        if ($teamId === 0 || ! in_array($plan, ['pro', 'premium'], true)) {
            return null;
        }

        $eventId = "{$id}:{$status}";

        // A chargeback is treated like a failed payment (grace, then Free).
        if ($status === 'paid' && (float) ($payment['amountChargedBack']['value'] ?? 0) > 0) {
            return new BillingEvent("{$id}:chargeback", BillingEvent::PAYMENT_FAILED, $teamId, $plan, $interval, $customerId, $subscriptionId);
        }

        if ($status === 'paid') {
            // A paid amount that differs from the configured price grants nothing.
            if (! hash_equals($this->amount($plan, $interval), (string) ($payment['amount']['value'] ?? ''))) {
                throw new RuntimeException('Paid amount does not match the configured price.');
            }

            if ($first && $customerId) {
                $subscriptionId = $this->ensureSubscription($customerId, $teamId, $plan, $interval, (string) $payment['amount']['value']);
            }

            $from = $local?->current_period_end?->isFuture() ? $local->current_period_end : now();
            $end = $interval === 'year' ? $from->copy()->addYear() : $from->copy()->addMonth();

            return new BillingEvent($eventId, BillingEvent::PAID, $teamId, $plan, $interval, $customerId, $subscriptionId, $end, $id, (int) str_replace('.', '', (string) $payment['amount']['value']));
        }

        // A failed renewal starts the grace period. A failed first payment
        // changes nothing: the team simply stays pending and can retry.
        if (! $first && in_array($status, ['failed', 'expired', 'canceled'], true)) {
            return new BillingEvent($eventId, BillingEvent::PAYMENT_FAILED, $teamId, $plan, $interval, $customerId, $subscriptionId);
        }

        return null;
    }

    public function cancel(Subscription $subscription): void
    {
        $this->assertConfigured();

        if (! $subscription->provider_customer_id || ! $subscription->provider_subscription_id) {
            return;
        }

        $response = $this->api()->delete("/customers/{$subscription->provider_customer_id}/subscriptions/{$subscription->provider_subscription_id}");

        // Already gone at Mollie counts as cancelled.
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /** Create the renewing subscription once; safe to call again for the same customer. */
    private function ensureSubscription(string $customerId, int $teamId, string $plan, string $interval, string $amount): string
    {
        $existing = $this->api()->get("/customers/{$customerId}/subscriptions")->throw()->json('_embedded.subscriptions', []);

        foreach ($existing as $sub) {
            if (($sub['status'] ?? '') === 'active' && ($sub['metadata']['plan'] ?? null) === $plan && ($sub['metadata']['interval'] ?? null) === $interval) {
                return (string) $sub['id'];
            }
        }

        $every = $interval === 'year' ? '12 months' : '1 month';
        $start = ($interval === 'year' ? now()->addYear() : now()->addMonth())->toDateString();

        $created = $this->api()->post("/customers/{$customerId}/subscriptions", [
            'amount' => ['currency' => $this->currency(), 'value' => $amount],
            'interval' => $every,
            'startDate' => $start,
            'description' => "ServerScope {$plan} ({$interval})",
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => ['team_id' => $teamId, 'plan' => $plan, 'interval' => $interval],
        ])->throw()->json();

        return (string) $created['id'];
    }

    private function customerFor(Team $team): string
    {
        if ($team->billing_customer_id) {
            return $team->billing_customer_id;
        }

        $owner = $team->users()->wherePivot('role', 'owner')->first();
        $customer = $this->api()->post('/customers', array_filter([
            'name' => $team->name,
            'email' => $owner?->email,
            'metadata' => ['team_id' => $team->id],
        ]))->throw()->json();

        $team->forceFill(['billing_customer_id' => $customer['id']])->save();

        return $customer['id'];
    }

    private function amount(string $plan, string $interval): string
    {
        $value = (string) config("serverscope.billing.prices.{$plan}.{$interval}");

        if (preg_match('/\A\d{1,6}\.\d{2}\z/', $value) !== 1) {
            throw new RuntimeException("The price for {$plan}/{$interval} must be an amount like 19.00.");
        }

        return $value;
    }

    private function currency(): string
    {
        return strtoupper((string) config('serverscope.billing.currency', 'EUR'));
    }

    private function token(): string
    {
        return hash_hmac('sha256', 'mollie-webhook', (string) config('serverscope.billing.webhook_secret'));
    }

    private function webhookUrl(): string
    {
        return route('webhooks.payments', ['t' => $this->token()]);
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) config('serverscope.billing.api_key'))->acceptJson()->baseUrl(self::BASE)->timeout(15);
    }

    /** Refuse to run half-configured, or with a key of the wrong mode. */
    private function assertConfigured(): void
    {
        $key = (string) config('serverscope.billing.api_key');

        if ($key === '' || (string) config('serverscope.billing.webhook_secret') === '') {
            throw new RuntimeException('Mollie needs SERVERSCOPE_BILLING_API_KEY and SERVERSCOPE_BILLING_WEBHOOK_SECRET.');
        }

        $test = config('serverscope.billing.test_mode');

        if (! str_starts_with($key, $test ? 'test_' : 'live_')) {
            throw new RuntimeException('The Mollie key does not match SERVERSCOPE_BILLING_TEST_MODE (test_ or live_).');
        }
    }
}
