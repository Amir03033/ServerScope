<?php

return [
    'title' => 'Plan and billing',
    'banner_link' => 'Go to billing',
    'current' => 'Current plan: :plan (:status)',
    'status' => ['active' => 'active', 'pending_payment' => 'payment pending'],
    'sub_status' => ['active' => 'active', 'past_due' => 'payment failed', 'canceled' => 'ended'],
    'subscription' => 'Subscription: :status',
    'renews' => 'renews on :date',
    'cancels' => 'ends at the end of the period',
    'interval' => ['month' => 'monthly', 'year' => 'yearly'],
    'pay' => 'Pay and activate',
    'cancel' => 'Cancel subscription',
    'cancel_note' => 'Your plan stays until the end of the paid period, then you return to Free. Your data stays; only the limits change, and history older than the Free retention is removed.',
    'not_available' => 'Online payment is not available yet. We will let you know when it is.',
    'flash' => [
        'unavailable' => 'This plan cannot be bought right now.',
        'no_subscription' => 'There is no active subscription.',
        'cancel_failed' => 'Cancelling did not work. Please try again later.',
        'cancel_scheduled' => 'Your subscription will end at the end of the paid period.',
    ],
];
