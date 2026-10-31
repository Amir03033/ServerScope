<?php

return [
    'title' => 'Abonnement en betaling',
    'banner_link' => 'Naar betaling',
    'current' => 'Huidig abonnement: :plan (:status)',
    'status' => ['active' => 'actief', 'pending_payment' => 'betaling volgt'],
    'sub_status' => ['active' => 'actief', 'past_due' => 'betaling mislukt', 'canceled' => 'beëindigd'],
    'subscription' => 'Abonnement: :status',
    'renews' => 'verlengt op :date',
    'cancels' => 'eindigt aan het einde van de periode',
    'interval' => ['month' => 'per maand', 'year' => 'per jaar'],
    'pay' => 'Betalen en activeren',
    'cancel' => 'Abonnement opzeggen',
    'cancel_note' => 'Je abonnement loopt door tot het einde van de betaalde periode, daarna ga je terug naar Free. Je gegevens blijven; alleen de limieten veranderen en historie ouder dan de Free-bewaartermijn wordt verwijderd.',
    'not_available' => 'Online betalen is nog niet beschikbaar. We laten het weten zodra het kan.',
    'flash' => [
        'unavailable' => 'Dit abonnement kan nu niet worden afgesloten.',
        'no_subscription' => 'Er is geen actief abonnement.',
        'cancel_scheduled' => 'Je abonnement eindigt aan het einde van de betaalde periode.',
    ],
];
