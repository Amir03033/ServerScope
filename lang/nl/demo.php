<?php

// De openbare alleen-lezen demo (/demo, App\Support\Demo).
return [
    'label' => 'Demo',
    'bar' => 'Je bekijkt een demo met voorbeeldgegevens (alleen lezen).',
    'register' => 'Account aanmaken',
    'leave' => 'Demo verlaten',
    'unavailable' => 'Niet beschikbaar in de demo.',
    'signed_in' => 'Je bent ingelogd met je eigen account. Log eerst uit om de demo te bekijken.',
    'example_ai' => 'Voorbeeldanalyse (demo)',
    'data' => [
        'team' => 'Acme (demo)',
        'user' => 'Demobezoeker',
        'status_page' => 'Acme-status',
        'status_page_text' => 'Live status van de Acme-webshop, de API en de blog.',
        'components' => ['shop' => 'Webshop', 'api' => 'API', 'blog' => 'Blog', 'db' => 'Database'],
        'rules' => ['cpu' => 'CPU boven 90%', 'disk' => 'Schijf bijna vol', 'logs' => 'Piek in log-fouten'],
        'jobs' => ['backup' => 'Nachtelijke databaseback-up', 'scheduler' => 'Laravel-planner', 'import' => 'Productimport leverancier'],
        'incidents' => [
            'cpu_title' => 'web-01 CPU overbelast',
            'cpu_text' => 'CPU 14 minuten boven 95% tijdens de avondpiek.',
            'shop_title' => 'shop.example.com reageert traag',
            'deploy_title' => 'api.example.com geeft fouten na een deploy',
            'deploy_text' => 'HTTP 500 op /orders gedurende 21 minuten, direct na release 2.14.0.',
            'job_title' => 'Productimport leverancier heeft zich niet op tijd gemeld',
            'grouped' => 'Gegroepeerd onder het CPU-incident: de website draait op web-01.',
            'note_ack' => 'Ik kijk ernaar: edge-01 is ook niet via SSH bereikbaar.',
            'note_cpu' => 'De piek valt samen met de nieuwsbrief van 19:00.',
            'resolution_cpu' => 'Tweede PHP-FPM-pool toegevoegd en pm.max_children verhoogd van 20 naar 40.',
            'resolution_deploy' => 'Teruggezet naar 2.13.4, daarna de migratie gerepareerd en 2.14.1 uitgebracht.',
        ],
        'learning' => [
            'cause' => 'Release 2.14.0 draaide een migratie die de tabel orders vergrendelde; verzoeken naar /orders liepen in een time-out en gaven 500.',
            'symptoms' => ['HTTP 500', 'trage queries', 'lock wait timeout'],
            'steps' => "Teruggezet naar 2.13.4.\nMigratie herschreven zodat de index zonder tabelvergrendeling wordt toegevoegd.\n2.14.1 uitgebracht.",
        ],
        'postmortem' => [
            'summary' => 'Na release 2.14.0 gaf de API 21 minuten lang fouten op bestellingen. Klanten konden via de app niet bestellen.',
            'impact' => 'Ongeveer 340 mislukte bestellingen via de API; de webshop zelf bleef werken.',
            'went_well' => 'De deploy was binnen een minuut aan het incident gekoppeld, waardoor de oorzaak snel duidelijk was.',
            'went_wrong' => 'De migratie was niet getest op een kopie van de productiedata.',
            'actions' => ['Migraties testen op een kopie op productiegrootte', 'Alert op 5xx-percentage per endpoint toevoegen', 'Terugzetstappen documenteren'],
        ],
        'ai' => [
            'summary' => 'Voorbeeld (niet van een model): de fouten begonnen twee minuten na release 2.14.0 op dezelfde API en stopten na het terugzetten.',
            'explanation' => 'Het tijdstip van de deploy en het herstel na het terugzetten wijzen naar de release; timing alleen is geen bewijs.',
            'confidence' => 'Er zijn geen applicatielogs van vóór de deploy om mee te vergelijken.',
            'uncertainty' => 'Of de database tegelijk belast werd door de nachtelijke import.',
            'step' => 'Vergelijk het slow-query-log van vóór en na de deploy.',
        ],
    ],
];
