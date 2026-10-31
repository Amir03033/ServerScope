<?php

namespace App\Billing\Providers;

use App\Billing\BillingEvent;
use App\Billing\PaymentProvider;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Http\Request;
use RuntimeException;

/** No provider chosen yet: nothing can be bought. */
class NullProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function checkoutOrigins(): array
    {
        return [];
    }

    public function checkoutUrl(Team $team, string $plan, string $interval, string $returnUrl): string
    {
        throw new RuntimeException('No payment provider is configured.');
    }

    public function parseWebhook(Request $request): ?BillingEvent
    {
        return null;
    }

    public function cancel(Subscription $subscription): void {}
}
