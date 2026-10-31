<?php

return [
    'names' => ['free' => 'Free', 'pro' => 'Pro', 'premium' => 'Premium'],

    'register' => [
        'chosen' => 'Gekozen abonnement: :plan',
        'free_note' => 'Free werkt direct.',
        'paid_note' => 'Betaling volgt later. Tot die tijd draait je account op de Free-limieten.',
    ],

    'banner' => [
        'over_limit' => 'Je zit boven de limieten van je huidige abonnement (:items). Alles blijft werken, maar je kunt niets toevoegen.',
        'pending' => 'Je hebt :plan gekozen, betaling volgt nog. Tot die tijd gelden de Free-limieten.',
    ],

    'items' => ['servers' => 'servers', 'domains' => 'domeinen', 'status_pages' => 'statuspagina\'s'],

    'limit' => [
        'servers' => 'Je abonnement staat maximaal :limit servers toe.',
        'domains' => 'Je abonnement staat maximaal :limit domeinen toe.',
        'status_pages' => 'Je abonnement staat maximaal :limit statuspagina\'s toe.',
    ],

    'feature' => [
        'api_tokens' => 'API-tokens zitten niet in je huidige abonnement.',
        'webhooks' => 'Webhooks zitten niet in je huidige abonnement.',
    ],
];
