<?php

return [
    'states' => [
        'healthy' => 'Healthy',
        'warning' => 'Warning',
        'critical' => 'Critical',
        'unknown' => 'Unknown',
        'offline' => 'Offline',
        'maintenance' => 'Maintenance',
        'paused' => 'Paused',
    ],
    'descriptions' => [
        'healthy' => 'Recent monitoring data shows no problems.',
        'warning' => 'A check is failing or a limit is close; not (yet) an outage.',
        'critical' => 'A confirmed failure: a check failed past its threshold or a critical incident is open.',
        'unknown' => 'Not enough recent data to tell. This is not proof that the resource is down.',
        'offline' => 'Not reachable: no agent data and websites on this server fail too.',
        'maintenance' => 'In a maintenance window: alerts are suppressed, checks keep running.',
        'paused' => 'Monitoring is paused; no checks run.',
    ],
    'freshness' => [
        'title' => 'Data freshness',
        'last_metrics' => 'Last metrics received',
        'last_success' => 'Last successful check',
        'last_check' => 'Last check',
        'next_check' => 'Next check expected',
        'never' => 'never',
        'stale_metrics' => 'No metrics for :age. The server is not necessarily down: the agent may have stopped or lost its connection.',
        'stale_checks' => 'The last check is older than expected (:age). The scheduler or queue may be delayed.',
    ],
];
