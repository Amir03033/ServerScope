<?php

// The public read-only demo (/demo, App\Support\Demo).
return [
    'label' => 'Demo',
    'bar' => 'You are viewing a demo with sample data (read only).',
    'register' => 'Create an account',
    'leave' => 'Leave demo',
    'unavailable' => 'Not available in the demo.',
    'signed_in' => 'You are signed in with your own account. Sign out first to view the demo.',
    'example_ai' => 'Example analysis (demo)',
    'data' => [
        'team' => 'Acme (demo)',
        'user' => 'Demo visitor',
        'status_page' => 'Acme status',
        'status_page_text' => 'Live status of the Acme webshop, API and blog.',
        'components' => ['shop' => 'Webshop', 'api' => 'API', 'blog' => 'Blog', 'db' => 'Database'],
        'rules' => ['cpu' => 'CPU above 90%', 'disk' => 'Disk almost full', 'logs' => 'Spike in log errors'],
        'jobs' => ['backup' => 'Nightly database backup', 'scheduler' => 'Laravel scheduler', 'import' => 'Supplier product import'],
        'incidents' => [
            'cpu_title' => 'web-01 CPU overload',
            'cpu_text' => 'CPU above 95% for 14 minutes during the evening peak.',
            'shop_title' => 'shop.example.com responds slowly',
            'deploy_title' => 'api.example.com returns errors after a deploy',
            'deploy_text' => 'HTTP 500 on /orders for 21 minutes, right after release 2.14.0.',
            'job_title' => 'Supplier product import did not check in on time',
            'grouped' => 'Grouped under the CPU incident: the website runs on web-01.',
            'note_ack' => 'Looking into it: edge-01 is not reachable over SSH either.',
            'note_cpu' => 'Spike matches the newsletter sent at 19:00.',
            'resolution_cpu' => 'Added a second PHP-FPM pool and raised pm.max_children from 20 to 40.',
            'resolution_deploy' => 'Rolled back to 2.13.4, then fixed the migration and released 2.14.1.',
        ],
        'learning' => [
            'cause' => 'Release 2.14.0 ran a migration that locked the orders table; requests to /orders timed out and returned 500.',
            'symptoms' => ['HTTP 500', 'slow queries', 'lock wait timeout'],
            'steps' => "Rolled back to 2.13.4.\nRewrote the migration to add the index without a table lock.\nReleased 2.14.1.",
        ],
        'postmortem' => [
            'summary' => 'For 21 minutes the API returned errors on order requests after release 2.14.0. Customers could not place orders through the mobile app.',
            'impact' => 'About 340 failed order requests; the webshop itself kept working.',
            'went_well' => 'The deploy was linked to the incident within a minute, which made the cause clear quickly.',
            'went_wrong' => 'The migration was not tested on a copy of production data.',
            'actions' => ['Test migrations on a production-size copy', 'Add an alert on 5xx rate per endpoint', 'Document the rollback steps'],
        ],
        'ai' => [
            'summary' => 'Example (not from a model): the errors started two minutes after release 2.14.0 on the same API, and stopped after the rollback.',
            'explanation' => 'The timing of the deploy and the recovery after the rollback point to the release; timing alone is not proof.',
            'confidence' => 'No application logs from before the deploy to compare with.',
            'uncertainty' => 'Whether the database was also under load from the nightly import.',
            'step' => 'Compare the slow query log before and after the deploy.',
        ],
    ],
];
