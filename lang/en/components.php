<?php

// Shared Blade components.
return [
    'secret_token' => [
        'title' => 'Save this token now',
        'once' => 'This token is shown only once and cannot be retrieved later. Store it safely and do not share it.',
    ],
    'line_chart' => [
        'no_data' => 'No measurements in this period yet.',
        'failed_checks' => 'failed checks',
    ],
    'dependencies' => [
        'title' => 'Dependencies',
        'text' => 'Confirmed relations are used to group incidents; suggested relations are not.',
        'manage' => 'Manage',
        'depends_on' => 'Depends on',
        'none_confirmed' => 'No confirmed dependencies',
        'affected' => 'Possibly affected by an outage',
        'nothing_known' => 'Nothing known',
        'pages' => ':count monitored page|:count monitored pages',
        'total' => ':count resources in total, including indirect dependencies.',
        'suggested' => 'Suggested relations (not confirmed)',
        'confirm' => 'Confirm',
    ],
];
