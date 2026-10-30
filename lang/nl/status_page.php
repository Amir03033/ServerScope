<?php

// Public status page (/status/{slug}). The Dutch state labels must stay
// identical to StatusPagePresenter::STATE_LABELS (LabelsTest).
return [
    'title_suffix' => 'Status',
    'headline' => [
        'operational' => 'Alle systemen werken normaal',
        'degraded' => 'Sommige systemen werken trager',
        'partial_outage' => 'Gedeeltelijke storing',
        'major_outage' => 'Er is een storing',
        'maintenance' => 'Gepland onderhoud',
        'unknown' => 'Status onbekend',
    ],
    'state' => [
        'operational' => 'Operationeel',
        'degraded' => 'Verminderde prestaties',
        'partial_outage' => 'Gedeeltelijke storing',
        'major_outage' => 'Grote storing',
        'maintenance' => 'Onderhoud',
        'unknown' => 'Onbekend',
    ],
    'incident_status' => [
        'detected' => 'Gedetecteerd',
        'identified' => 'Onderzocht',
        'investigating' => 'In onderzoek',
        'resolved' => 'Opgelost',
    ],
    'incident' => [
        'outage_at' => 'Storing bij :component',
        'disruption_at' => 'Verstoring bij :component',
        'a_component' => 'een component',
    ],
    'active_incidents' => 'Lopende incidenten',
    'since' => 'Sinds :time',
    'components' => 'Onderdelen',
    'availability_per_day' => 'Beschikbaarheid per dag',
    'no_data' => 'geen gegevens',
    'days_ago' => '90 dagen geleden',
    'available' => ':percent beschikbaar',
    'no_data_yet' => 'nog geen gegevens',
    'today' => 'vandaag',
    'no_components' => 'Er zijn nog geen onderdelen gepubliceerd.',
    'resolved_recent' => 'Opgelost (laatste 7 dagen)',
    'updated' => 'Bijgewerkt :time',
    'refreshes' => 'ververst elke minuut',
    'powered_by' => 'aangedreven door ServerScope',
];
