<?php

// System health: the state of ServerScope itself.
return [
    'title' => 'System health',
    'subtitle' => 'The health of ServerScope itself: without the scheduler and queue worker, no checks run and no notifications are sent.',
    'overview' => 'Overview',
    'overview_text' => 'Re-evaluated every minute. See also the :docs and docs/DEPLOYMENT.md.',
    'docs' => 'documentation',
    'overall' => [
        'healthy' => 'Healthy',
        'warning' => 'Needs attention',
        'critical' => 'Disrupted',
    ],
    'check_status' => [
        'healthy' => 'Healthy',
        'warning' => 'Attention',
        'critical' => 'Problem',
    ],
    'col_part' => 'Component',
    'col_status' => 'Status',
    'col_detail' => 'Details',
    'checks' => [
        'scheduler' => 'Scheduler',
        'queue' => 'Queue',
        'overdue' => 'Checks on time',
        'deliveries' => 'Notifications and webhooks',
        'discovery' => 'Page discovery',
    ],
    'detail' => [
        'no_heartbeat' => 'No heartbeat received yet. Check the cron entry for `php artisan schedule:run`.',
        'heartbeat' => 'Last heartbeat :time.',
        'queue_driver' => 'Queue driver :driver: backlog cannot be measured from the database.',
        'queue_unreadable' => 'The queue could not be read.',
        'queue_pending' => ':count jobs in the queue',
        'queue_oldest' => ', oldest waiting :minutes min',
        'queue_failed' => '; :count failed jobs in 24 hours.',
        'on_schedule' => 'All checks are on schedule.',
        'overdue' => ':domains domain and :pages page checks more than :minutes minutes behind.',
        'deliveries' => ':mail failed notifications and :webhooks failed webhook deliveries in 24 hours.',
        'discovery' => ':count failed discoveries in 24 hours (existing monitoring is unchanged).',
    ],
];
