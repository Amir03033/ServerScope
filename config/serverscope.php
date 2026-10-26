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

        // Record types whose changes trigger a notification. All changes are
        // stored in the DNS history regardless.
        'dns_alert_types' => array_values(array_filter(explode(',', (string) env('SERVERSCOPE_DNS_ALERT_TYPES', 'A,AAAA,CNAME,MX,NS,CAA')))),

        // Abuse prevention: the shortest allowed HTTP interval and the
        // maximum number of monitored domains.
        'min_interval_minutes' => (int) env('SERVERSCOPE_MIN_INTERVAL', 1),
        'max_domains' => (int) env('SERVERSCOPE_MAX_DOMAINS', 1000),

        // How often the slower checks run (minutes).
        'ssl_interval_minutes' => (int) env('SERVERSCOPE_SSL_INTERVAL', 360),
        'dns_interval_minutes' => (int) env('SERVERSCOPE_DNS_INTERVAL', 15),
        'expiry_interval_minutes' => (int) env('SERVERSCOPE_EXPIRY_INTERVAL', 1440),
        'rdap_bootstrap_url' => env('SERVERSCOPE_RDAP_BOOTSTRAP_URL', 'https://data.iana.org/rdap/dns.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incidents
    |--------------------------------------------------------------------------
    */

    'incidents' => [
        // A problem that recurs within this many minutes after automatic or
        // manual resolution reopens the previous incident.
        'reopen_window_minutes' => (int) env('SERVERSCOPE_INCIDENT_REOPEN_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications and escalation
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // A reopened incident within this many minutes after its last
        // notification does not notify again (flapping protection).
        'reopen_cooldown_minutes' => (int) env('SERVERSCOPE_NOTIFY_REOPEN_COOLDOWN', 15),
        'dns_cooldown_minutes' => (int) env('SERVERSCOPE_NOTIFY_DNS_COOLDOWN', 30),
        'max_mails_per_hour' => (int) env('SERVERSCOPE_MAX_MAILS_PER_HOUR', 30),
        // Chat webhooks (Discord) post the same incident event in the same
        // state at most once per this many minutes (0 = no limit).
        'chat_cooldown_minutes' => (int) env('SERVERSCOPE_NOTIFY_CHAT_COOLDOWN', 15),
    ],

    'escalation' => [
        // Minutes an open incident may stay unacknowledged before it is
        // escalated, per severity (null/0 = never).
        'after_minutes' => [
            'critical' => (int) env('SERVERSCOPE_ESCALATE_CRITICAL_MINUTES', 15),
            'high' => (int) env('SERVERSCOPE_ESCALATE_HIGH_MINUTES', 30),
            'warning' => (int) env('SERVERSCOPE_ESCALATE_WARNING_MINUTES', 120),
            'info' => null,
        ],
        // Extra recipients for escalations (comma separated e-mail addresses).
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERSCOPE_ESCALATION_EMAILS', ''))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))),
    ],

    'reports' => [
        // Availability target shown in reports (calculated, not contractual).
        'default_target' => (float) env('SERVERSCOPE_REPORT_TARGET', 99.9),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Days to keep each kind of history (serverscope:prune runs daily).
    | Empty = keep forever (the default). Note that reports can only cover
    | periods for which data is kept.
    |
    */

    'retention' => [
        'metric_samples_days' => env('SERVERSCOPE_RETAIN_METRICS_DAYS'),
        'domain_checks_days' => env('SERVERSCOPE_RETAIN_DOMAIN_CHECKS_DAYS'),
        'notification_logs_days' => env('SERVERSCOPE_RETAIN_NOTIFICATION_LOGS_DAYS'),
        'notifications_days' => env('SERVERSCOPE_RETAIN_READ_NOTIFICATIONS_DAYS'),
        'audit_logs_days' => env('SERVERSCOPE_RETAIN_AUDIT_LOGS_DAYS'),
    ],

    'api' => [
        'requests_per_minute' => (int) env('SERVERSCOPE_API_RATE_LIMIT', 120),
    ],

    'webhooks' => [
        // Plain http endpoints are refused unless explicitly allowed.
        'allow_http' => (bool) env('SERVERSCOPE_WEBHOOKS_ALLOW_HTTP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation
    |--------------------------------------------------------------------------
    |
    | Public documentation site (a separate application). Linked from the
    | sidebar and from setup screens; set empty to hide the links.
    |
    */

    'docs_url' => rtrim((string) env('SERVERSCOPE_DOCS_URL', 'https://info.serverscope.nl/nl/docs'), '/'),

];
