<?php

return [
    'states' => [
        'healthy' => 'Gezond',
        'warning' => 'Waarschuwing',
        'critical' => 'Kritiek',
        'unknown' => 'Onbekend',
        'offline' => 'Offline',
        'maintenance' => 'Onderhoud',
        'paused' => 'Gepauzeerd',
    ],
    'descriptions' => [
        'healthy' => 'Recente meetgegevens tonen geen problemen.',
        'warning' => 'Een controle faalt of een limiet is dichtbij; (nog) geen storing.',
        'critical' => 'Een bevestigde storing: een controle faalde boven de drempel of er is een kritiek incident open.',
        'unknown' => 'Te weinig recente gegevens om het te zeggen. Dit bewijst niet dat het onderdeel uit de lucht is.',
        'offline' => 'Niet bereikbaar: geen agentgegevens en websites op deze server falen ook.',
        'maintenance' => 'In een onderhoudsvenster: meldingen worden onderdrukt, controles blijven draaien.',
        'paused' => 'Bewaking is gepauzeerd; er draaien geen controles.',
    ],
    'freshness' => [
        'title' => 'Actualiteit van de gegevens',
        'last_metrics' => 'Laatste metingen ontvangen',
        'last_success' => 'Laatste geslaagde controle',
        'last_check' => 'Laatste controle',
        'next_check' => 'Volgende controle verwacht',
        'never' => 'nooit',
        'stale_metrics' => 'Al :age geen metingen. De server is niet per se uit de lucht: de agent kan gestopt zijn of geen verbinding hebben.',
        'stale_checks' => 'De laatste controle is ouder dan verwacht (:age). De planner of wachtrij kan vertraagd zijn.',
    ],
];
