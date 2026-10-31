<?php

namespace App\Billing;

use Carbon\CarbonInterface;

/** A provider webhook, normalised. Drivers translate their payloads into this. */
final class BillingEvent
{
    public const PAID = 'paid';

    public const PAYMENT_FAILED = 'payment_failed';

    public const CANCELED = 'canceled';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $teamId,
        public readonly string $plan,
        public readonly string $interval = 'month',
        public readonly ?string $customerId = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?CarbonInterface $periodEnd = null,
    ) {}
}
