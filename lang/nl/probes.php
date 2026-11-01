<?php

return [
    'title' => 'Externe probe',
    'text' => 'Een tweede meetlocatie die een falende website opnieuw controleert voordat een incident wordt geopend.',
    'healthy' => 'Beschikbaar',
    'unavailable' => 'Niet beschikbaar',
    'last_seen' => 'Laatste contact',
    'never' => 'nooit',
    'last_day' => 'Bevestigingen in de laatste 24 uur',
    'domain_title' => 'Controles vanaf een tweede locatie',
    'domain_text' => 'Hercontroles door de externe probe wanneer deze website vanaf de hoofdlocatie faalde.',
    'outcome' => [
        'pending' => 'Wacht op de probe',
        'confirmed' => 'Storing bevestigd vanaf beide locaties',
        'main_only' => 'Storing alleen vanaf de hoofdlocatie gezien',
        'probe_only' => 'Storing alleen vanaf de externe probe gezien',
        'not_reproduced' => 'Bereikbaar vanaf beide locaties',
        'unavailable' => 'Geen antwoord van de probe (niet bevestigd)',
    ],
];
