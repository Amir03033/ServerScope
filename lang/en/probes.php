<?php

return [
    'title' => 'Remote probe',
    'text' => 'A second monitoring location that re-checks a failing website before an incident is opened.',
    'healthy' => 'Available',
    'unavailable' => 'Unavailable',
    'last_seen' => 'Last contact',
    'never' => 'never',
    'last_day' => 'Confirmations in the last 24 hours',
    'domain_title' => 'Second-location checks',
    'domain_text' => 'Re-checks by the remote probe when this website failed from the main location.',
    'outcome' => [
        'pending' => 'Waiting for the probe',
        'confirmed' => 'Failure confirmed from both locations',
        'main_only' => 'Failure seen only from the main location',
        'probe_only' => 'Failure seen only from the remote probe',
        'not_reproduced' => 'Reachable from both locations',
        'unavailable' => 'No answer from the probe (not confirmed)',
    ],
];
