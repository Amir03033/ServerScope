<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert notifications
    |--------------------------------------------------------------------------
    |
    | When enabled, administrators receive an e-mail when an alert is
    | triggered and when it recovers. Notifications are only sent on state
    | transitions, never for every evaluated sample. Requires a working
    | MAIL_* configuration and a queue worker (see below).
    |
    */

    'alert_mail_enabled' => (bool) env('SERVERSCOPE_ALERT_MAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Scheduler-driven queue worker
    |--------------------------------------------------------------------------
    |
    | Hosting panels such as Plesk make it easy to run the scheduler from
    | cron but harder to supervise a long-running queue worker. When this is
    | enabled, the scheduler starts a short-lived worker every minute that
    | processes pending jobs and exits. Disable it if a supervised
    | `queue:work` process is already running.
    |
    */

    'scheduler_queue_worker' => (bool) env('SERVERSCOPE_SCHEDULER_QUEUE_WORKER', true),

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC (config/app.php). Pages show times and
    | interpret entered dates in this timezone.
    |
    */

    'display_timezone' => env('SERVERSCOPE_DISPLAY_TIMEZONE', 'Europe/Amsterdam'),

];
