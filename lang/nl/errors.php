<?php

return [
    'actions' => [
        'dashboard' => 'Naar het dashboard',
        'home' => 'Naar de homepage',
        'back' => 'Ga terug',
        'retry' => 'Opnieuw proberen',
        'status' => 'Statuspagina bekijken',
        'login' => 'Opnieuw inloggen',
    ],
    '403' => [
        'signal' => 'Geen toegang',
        'title' => 'Je hebt geen toegang tot deze pagina',
        'text' => 'Je account heeft geen rechten voor deze actie. Vraag een eigenaar of beheerder van je team als je toegang nodig hebt.',
    ],
    '404' => [
        'signal' => 'Signaal niet gevonden',
        'title' => '404 — Signaal niet gevonden',
        'text' => 'De pagina die je zoekt bestaat niet of is verplaatst.',
    ],
    '419' => [
        'signal' => 'Sessie verlopen',
        'title' => 'Deze pagina is verlopen',
        'text' => 'Voor je veiligheid is het formulier verlopen. Laad de pagina opnieuw en probeer het nog eens; je wijzigingen zijn niet opgeslagen.',
    ],
    '429' => [
        'signal' => 'Te veel verzoeken',
        'title' => 'Te veel verzoeken',
        'text' => 'Je hebt in korte tijd veel verzoeken gedaan. Wacht even en probeer het opnieuw.',
    ],
    '500' => [
        'signal' => 'Interne fout',
        'title' => 'Er ging aan onze kant iets mis',
        'text' => 'De fout is vastgelegd. Probeer het zo opnieuw; blijft het gebeuren, neem dan contact op.',
    ],
    '503' => [
        'signal' => 'Onderhoud',
        'title' => 'ServerScope is in onderhoud',
        'text' => 'We voeren gepland onderhoud uit om de betrouwbaarheid te verbeteren. Probeer het straks opnieuw.',
        'no_estimate' => 'Er is nog geen tijdstip voor terugkeer bekend.',
        'retry' => 'Naar verwachting over ongeveer een minuut terug.|Naar verwachting over ongeveer :minutes minuten terug.',
    ],
];
