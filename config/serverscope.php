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
    | Website (multi-page) monitoring and discovery
    |--------------------------------------------------------------------------
    |
    | Discovery only follows sitemaps, robots.txt and links on the website's
    | own host (subdomains only when enabled per website). Every request goes
    | through SafeHttpClient, so the SSRF rules above apply to each URL and
    | every redirect.
    |
    */

    'websites' => [
        'max_pages_per_website' => (int) env('SERVERSCOPE_MAX_PAGES_PER_WEBSITE', 1000),
        'discovery_default_max_pages' => (int) env('SERVERSCOPE_DISCOVERY_DEFAULT_PAGES', 50),
        'discovery_max_pages_limit' => (int) env('SERVERSCOPE_DISCOVERY_MAX_PAGES', 1000),
        'discovery_max_requests' => (int) env('SERVERSCOPE_DISCOVERY_MAX_REQUESTS', 300),
        'discovery_max_sitemaps' => (int) env('SERVERSCOPE_DISCOVERY_MAX_SITEMAPS', 25),
        'discovery_request_delay_ms' => (int) env('SERVERSCOPE_DISCOVERY_DELAY_MS', 250),
        'discovery_timeout_seconds' => (int) env('SERVERSCOPE_DISCOVERY_TIMEOUT', 10),
        'discovery_max_duration_seconds' => (int) env('SERVERSCOPE_DISCOVERY_MAX_DURATION', 240),
        // Page checks queued per website and in total per scheduler run;
        // the rest stays due for the next minute (no job storms).
        'checks_per_website_per_run' => (int) env('SERVERSCOPE_PAGE_CHECKS_PER_WEBSITE', 20),
        'max_checks_per_run' => (int) env('SERVERSCOPE_PAGE_CHECKS_PER_RUN', 500),
        // Content changes above this share of changed text are "significant".
        'significant_change_ratio' => (float) env('SERVERSCOPE_SIGNIFICANT_CHANGE_RATIO', 0.10),
        // Shared-cause page failures are grouped once at least this many
        // pages of a website fail for the same reason.
        'group_min_pages' => (int) env('SERVERSCOPE_PAGE_GROUP_MIN', 2),
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
        // A newly detected root cause adopts dependent incidents that
        // opened at most this many minutes before it.
        'correlation_window_minutes' => (int) env('SERVERSCOPE_CORRELATION_WINDOW_MINUTES', 30),
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

    /*
    |--------------------------------------------------------------------------
    | Monitoring agent
    |--------------------------------------------------------------------------
    |
    | latest_version: agents reporting an older version are flagged.
    | known_checksums: SHA-256 checksums of released agent builds (comma
    | separated). When set, an agent reporting another checksum is flagged
    | as an unknown build. Empty = integrity is shown as "not verified".
    |
    */

    'agent' => [
        'latest_version' => env('SERVERSCOPE_AGENT_LATEST_VERSION', '0.2.0'),
        'known_checksums' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERSCOPE_AGENT_CHECKSUMS', ''))))),
        // Agent sources in the repository (tests and development only;
        // nothing is served from here, see App\Services\AgentPackage).
        'source_path' => env('SERVERSCOPE_AGENT_SOURCE', base_path('agent')),
        'cache_path' => storage_path('app/agent'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Predictive monitoring
    |--------------------------------------------------------------------------
    |
    | Anomaly detection and capacity forecasts run hourly
    | (serverscope:analyze). Results are always shown; incidents are only
    | opened for forecasts that cross a limit within alert_horizon_days with
    | at least medium confidence, and for anomalies only when
    | anomaly_incidents is enabled. Servers can opt out and set their own
    | sensitivity (low, medium, high or off).
    |
    */

    'predictive' => [
        'enabled' => (bool) env('SERVERSCOPE_PREDICTIVE', true),
        'alerts_enabled' => (bool) env('SERVERSCOPE_PREDICTIVE_ALERTS', true),
        'alert_horizon_days' => (int) env('SERVERSCOPE_PREDICTIVE_HORIZON_DAYS', 7),
        'anomaly_incidents' => (bool) env('SERVERSCOPE_ANOMALY_INCIDENTS', false),
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
        'page_checks_days' => env('SERVERSCOPE_RETAIN_PAGE_CHECKS_DAYS'),
        'page_changes_days' => env('SERVERSCOPE_RETAIN_PAGE_CHANGES_DAYS'),
        'discovery_runs_days' => env('SERVERSCOPE_RETAIN_DISCOVERY_RUNS_DAYS'),
        'service_events_days' => env('SERVERSCOPE_RETAIN_SERVICE_EVENTS_DAYS'),
        'predictions_days' => env('SERVERSCOPE_RETAIN_PREDICTIONS_DAYS'),
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

    /*
    |--------------------------------------------------------------------------
    | Teams (multi-tenancy)
    |--------------------------------------------------------------------------
    |
    | Every customer is a team that only sees its own data. Public sign-up
    | (a new account with its own team) and invitations of existing
    | accounts into another team are off until explicitly enabled.
    | Do not enable either before the blockers in docs/AUDIT.md section 5
    | are fixed (domain names unique per team, invitations the recipient
    | accepts, e-mail verification for registration).
    |
    */

    'tenancy' => [
        'registration_enabled' => (bool) env('SERVERSCOPE_REGISTRATION_ENABLED', false),
        'invitations_enabled' => (bool) env('SERVERSCOPE_TEAM_INVITATIONS_ENABLED', false),
        // Automated tests only: records created without any team context
        // go to the default team. Never applies outside tests.
        'test_fallback' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server enrollment (install.sh)
    |--------------------------------------------------------------------------
    |
    | One-time tokens with which install.sh connects a new Linux server.
    | allow_insecure_url: only for local testing; never in production. Without
    | it the "Server toevoegen" page and install.sh refuse an http APP_URL.
    |
    */

    'enrollment' => [
        'ttl_minutes' => (int) env('SERVERSCOPE_ENROLLMENT_TTL_MINUTES', 60),
        'max_pending' => (int) env('SERVERSCOPE_ENROLLMENT_MAX_PENDING', 10),
        'attempts_per_minute' => (int) env('SERVERSCOPE_ENROLLMENT_ATTEMPTS_PER_MINUTE', 10),
        'allow_insecure_url' => (bool) env('SERVERSCOPE_ENROLLMENT_ALLOW_INSECURE_URL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed agent releases (phase 3)
    |--------------------------------------------------------------------------
    |
    | install.sh and the updater only install releases signed with the
    | offline release key (scripts/release/). key_path holds the PUBLIC
    | keys (*.pub, PEM) that are trusted; several at once during a key
    | rotation. Releases are imported with serverscope:release:import and
    | stored in storage_path. pending_ttl_hours: an update request that no
    | updater picked up expires after this many hours.
    |
    */

    'releases' => [
        'key_path' => env('SERVERSCOPE_RELEASE_KEY_PATH', resource_path('agent/release-keys')),
        'storage_path' => storage_path('app/agent-releases'),
        'pending_ttl_hours' => (int) env('SERVERSCOPE_UPDATE_PENDING_TTL_HOURS', 24),
        // The updater checks in every 5 minutes; after this many minutes
        // without a check-in the dashboard shows it as not active.
        'updater_stale_minutes' => (int) env('SERVERSCOPE_UPDATER_STALE_MINUTES', 30),
    ],

    'docs_url' => rtrim((string) env('SERVERSCOPE_DOCS_URL', 'https://info.serverscope.nl/nl/docs'), '/'),

];
