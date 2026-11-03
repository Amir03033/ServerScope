<?php

// Mission control at the top of the dashboard (App\Services\Live\MissionControl).
return [
    'kicker' => 'Health',
    'score_label' => 'Health score: :score out of 100',
    'score_none' => 'No score yet',
    'headline' => [
        'none' => 'Nothing to watch yet',
        'ok' => 'All good',
        'ok_but' => 'All good, :issue',
        'attention' => 'Needs attention: :issue',
    ],
    'issues' => [
        'incident_major' => ':count critical or major incident|:count critical or major incidents',
        'incident_minor' => ':count open incident|:count open incidents',
        'server_offline' => ':count server offline|:count servers offline',
        'site_down' => ':count website down|:count websites down',
        'warning' => ':count warning|:count warnings',
        'slow' => ':count website slower than usual|:count websites slower than usual',
        'expiring' => ':count certificate or domain expires within 7 days|:count certificates or domains expire within 7 days',
    ],
    'how' => 'The score starts at 100; every open problem above costs points.',
    'counts' => ':servers_ok/:servers servers · :sites_ok/:sites websites · open incidents: :incidents',
    'tiles_title' => 'Everything at a glance',
    'tiles_empty' => 'Add a server or website to see it here.',
    'timeline_title' => 'Last 24 hours',
    'timeline_empty' => 'No incidents, deployments or DNS changes in the last 24 hours.',
    'legend' => ['incident' => 'Incident', 'deploy' => 'Deployment', 'dns' => 'DNS change'],
    'focus' => 'Focus',
    'focus_hint' => 'Show only what needs attention',
    'focus_calm' => 'Nothing to do',
    'focus_calm_text' => 'Everything is running as it should. Turn off focus to see all details.',
    'details' => 'Details',
    'details_text' => 'Everything below is the dashboard as before.',
];
