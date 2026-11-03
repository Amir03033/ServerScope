<?php

// Commandopalet (Ctrl/⌘ K): partials/search-dialog.blade.php, resources/js/palette.js.
return [
    'placeholder' => 'Zoek of typ een opdracht… (bijv. "offline", "traag", "skyline")',
    'tip' => 'Probeer "offline", "trage websites" of "incidenten"',
    'go' => 'Ga naar :page',
    'add_server' => 'Server toevoegen',
    'theme' => 'Thema wisselen (licht / donker / systeem)',
    'focus' => 'Focusmodus op het dashboard',
    'filters' => [
        'servers_offline' => 'Servers die offline zijn',
        'sites_down' => 'Websites die onbereikbaar zijn',
        'sites_slow' => 'Websites, traagste eerst',
        'incidents_open' => 'Open incidenten',
        'incidents_unacknowledged' => 'Incidenten die nog niemand bevestigde',
        'incidents_resolved' => 'Opgeloste incidenten',
    ],
    'keywords' => [
        'servers_offline' => 'offline down server servers onbereikbaar uit plat',
        'sites_down' => 'down storing websites sites domeinen onbereikbaar kritiek plat stuk',
        'sites_slow' => 'traag trage traagste langzaam responstijd websites sites prestaties slow',
        'incidents_open' => 'incidenten open actief problemen storingen',
        'incidents_unacknowledged' => 'onbevestigd nieuw incidenten niemand bevestigen',
        'incidents_resolved' => 'opgelost opgeloste gesloten gisteren incidenten geschiedenis',
        'add_server' => 'server toevoegen nieuw agent installeren',
        'theme' => 'thema donker licht modus weergave dark light',
        'focus' => 'focus rust aandacht alleen problemen',
    ],
    'js' => [
        'view_only' => 'Verandert alleen de weergave',
        'groups' => [
            'recent' => 'Recent bekeken',
            'filter' => 'Snelle filters',
            'view' => 'Acties',
            'go' => 'Ga naar',
            'commands' => 'Opdrachten',
        ],
    ],
];
