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

    /*
    |--------------------------------------------------------------------------
    | Outbound monitoring (domains, HTTP, TLS, DNS, RDAP)
    |--------------------------------------------------------------------------
    |
    | All outbound checks go through App\Monitoring\Net\SafeHttpClient and
    | TargetGuard. Private, loopback, link-local and reserved addresses are
    | always blocked unless explicitly allowed below (never do this in
    | production).
    |
    */

    'monitoring' => [
        'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERSCOPE_DNS_NAMESERVERS', '1.1.1.1,8.8.8.8,9.9.9.9'))))),
        'dns_timeout' => (float) env('SERVERSCOPE_DNS_TIMEOUT', 3),
        'allowed_ports' => array_map('intval', array_filter(explode(',', (string) env('SERVERSCOPE_MONITOR_PORTS', '80,443')))),
        'allow_private_targets' => (bool) env('SERVERSCOPE_ALLOW_PRIVATE_TARGETS', false),
        'allowed_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERSCOPE_ALLOWED_CIDRS', ''))))),
        'max_redirects' => (int) env('SERVERSCOPE_MAX_REDIRECTS', 5),
        'max_response_bytes' => (int) env('SERVERSCOPE_MAX_RESPONSE_BYTES', 1048576),
        'requests_per_ip_per_minute' => (int) env('SERVERSCOPE_REQUESTS_PER_IP_PER_MINUTE', 30),
        'ca_file' => env('SERVERSCOPE_CA_FILE'),
        'user_agent' => env('SERVERSCOPE_USER_AGENT', 'ServerScope-Monitor/1.0 (+https://serverscope.nl)'),
    ],

];
