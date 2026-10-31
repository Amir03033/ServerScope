<?php

return [
    'names' => ['free' => 'Free', 'pro' => 'Pro', 'premium' => 'Premium'],

    'register' => [
        'chosen' => 'Chosen plan: :plan',
        'free_note' => 'Free works immediately.',
        'paid_note' => 'Payment follows later. Until then your account runs on the Free limits.',
    ],

    'banner' => [
        'over_limit' => 'You are over the limits of your current plan (:items). Everything keeps working, but you cannot add more.',
        'pending' => 'You chose :plan, payment follows. Until then the Free limits apply.',
    ],

    'items' => ['servers' => 'servers', 'domains' => 'domains', 'status_pages' => 'status pages'],

    'limit' => [
        'servers' => 'Your plan allows at most :limit servers.',
        'domains' => 'Your plan allows at most :limit domains.',
        'status_pages' => 'Your plan allows at most :limit status pages.',
    ],

    'feature' => [
        'api_tokens' => 'API tokens are not included in your current plan.',
        'webhooks' => 'Webhooks are not included in your current plan.',
    ],
];
