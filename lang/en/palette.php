<?php

// Command palette (Ctrl/⌘ K): partials/search-dialog.blade.php, resources/js/palette.js.
return [
    'placeholder' => 'Search or type a command… (e.g. "offline", "slow", "skyline")',
    'tip' => 'Try "offline", "slow websites" or "incidents"',
    'go' => 'Go to :page',
    'add_server' => 'Add a server',
    'theme' => 'Switch theme (light / dark / system)',
    'focus' => 'Focus mode on the dashboard',
    'filters' => [
        'servers_offline' => 'Servers that are offline',
        'sites_down' => 'Websites that are down',
        'sites_slow' => 'Websites, slowest first',
        'incidents_open' => 'Open incidents',
        'incidents_unacknowledged' => 'Incidents nobody acknowledged yet',
        'incidents_resolved' => 'Resolved incidents',
    ],
    'keywords' => [
        'servers_offline' => 'offline down server servers unreachable dead',
        'sites_down' => 'down outage websites sites domains unreachable critical broken',
        'sites_slow' => 'slow slowest response time websites sites performance latency',
        'incidents_open' => 'incidents open active problems outages',
        'incidents_unacknowledged' => 'unacknowledged new incidents nobody acknowledge',
        'incidents_resolved' => 'resolved fixed closed yesterday incidents history',
        'add_server' => 'add new server install agent',
        'theme' => 'theme dark light mode appearance',
        'focus' => 'focus calm attention only problems',
    ],
    'js' => [
        'view_only' => 'Changes only the view',
        'groups' => [
            'recent' => 'Recently viewed',
            'filter' => 'Quick filters',
            'view' => 'Actions',
            'go' => 'Go to',
            'commands' => 'Commands',
        ],
    ],
];
