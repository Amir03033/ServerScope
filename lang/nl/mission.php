<?php

// Mission control bovenaan het dashboard (App\Services\Live\MissionControl).
return [
    'kicker' => 'Gezondheid',
    'score_label' => 'Gezondheidsscore: :score van de 100',
    'score_none' => 'Nog geen score',
    'headline' => [
        'none' => 'Nog niets om te bewaken',
        'ok' => 'Alles in orde',
        'ok_but' => 'Alles in orde, :issue',
        'attention' => 'Aandacht nodig: :issue',
    ],
    'issues' => [
        'incident_major' => ':count kritiek of ernstig incident|:count kritieke of ernstige incidenten',
        'incident_minor' => ':count open incident|:count open incidenten',
        'server_offline' => ':count server offline|:count servers offline',
        'site_down' => ':count website onbereikbaar|:count websites onbereikbaar',
        'warning' => ':count waarschuwing|:count waarschuwingen',
        'slow' => ':count website trager dan normaal|:count websites trager dan normaal',
        'expiring' => ':count certificaat of domein verloopt binnen 7 dagen|:count certificaten of domeinen verlopen binnen 7 dagen',
    ],
    'how' => 'De score begint bij 100; elk open probleem hierboven kost punten.',
    'counts' => ':servers_ok/:servers servers · :sites_ok/:sites websites · open incidenten: :incidents',
    'tiles_title' => 'Alles in één oogopslag',
    'tiles_empty' => 'Voeg een server of website toe om hem hier te zien.',
    'timeline_title' => 'Afgelopen 24 uur',
    'timeline_empty' => 'Geen incidenten, deploys of DNS-wijzigingen in de afgelopen 24 uur.',
    'legend' => ['incident' => 'Incident', 'deploy' => 'Deploy', 'dns' => 'DNS-wijziging'],
    'focus' => 'Focus',
    'focus_hint' => 'Toon alleen wat aandacht nodig heeft',
    'focus_calm' => 'Niets te doen',
    'focus_calm_text' => 'Alles draait zoals het hoort. Zet focus uit om alle details te zien.',
    'details' => 'Details',
    'details_text' => 'Alles hieronder is het dashboard zoals je het kent.',
    'more' => '+:count meer',
    'less' => 'Minder tonen',
];
