<?php

// System health: the state of ServerScope itself.
return [
    'title' => 'Systeemstatus',
    'subtitle' => 'De gezondheid van ServerScope zelf: zonder scheduler en queue-worker worden er geen controles uitgevoerd en geen meldingen verstuurd.',
    'overview' => 'Overzicht',
    'overview_text' => 'Wordt elke minuut opnieuw beoordeeld. Zie ook de :docs en docs/DEPLOYMENT.md.',
    'docs' => 'documentatie',
    'overall' => [
        'healthy' => 'In orde',
        'warning' => 'Aandacht nodig',
        'critical' => 'Verstoord',
    ],
    'check_status' => [
        'healthy' => 'In orde',
        'warning' => 'Aandacht',
        'critical' => 'Probleem',
    ],
    'col_part' => 'Onderdeel',
    'col_status' => 'Status',
    'col_detail' => 'Toelichting',
    'checks' => [
        'scheduler' => 'Scheduler',
        'queue' => 'Wachtrij',
        'overdue' => 'Controles op tijd',
        'deliveries' => 'Meldingen en webhooks',
        'discovery' => 'Paginaontdekking',
    ],
    'detail' => [
        'no_heartbeat' => 'Nog nooit een hartslag ontvangen. Controleer de cronregel voor `php artisan schedule:run`.',
        'heartbeat' => 'Laatste hartslag :time.',
        'queue_driver' => 'Wachtrijdriver :driver: achterstand niet meetbaar vanuit de database.',
        'queue_unreadable' => 'Wachtrij kon niet worden uitgelezen.',
        'queue_pending' => ':count taken in de wachtrij',
        'queue_oldest' => ', oudste wacht :minutes min',
        'queue_failed' => '; :count mislukte taken in 24 uur.',
        'on_schedule' => 'Alle controles lopen op schema.',
        'overdue' => ':domains domein- en :pages paginacontroles meer dan :minutes minuten achter.',
        'deliveries' => ':mail mislukte meldingen en :webhooks mislukte webhookleveringen in 24 uur.',
        'discovery' => ':count mislukte ontdekkingen in 24 uur (bestaande monitoring blijft ongewijzigd).',
    ],
];
