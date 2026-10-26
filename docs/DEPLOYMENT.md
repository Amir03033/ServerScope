# ServerScope — Deployment and operations (Plesk)

This describes what production needs after the changes on branch
`claude/hopeful-euler-bbinww`. Nothing here has been run on the production
server; follow it as a checklist.

## 1. Requirements

| Item | Requirement | Notes |
|---|---|---|
| PHP | **8.4.1 or newer** for web *and* CLI | `composer.lock` contains Symfony 8.1 packages that need PHP ≥ 8.4.1, while `composer.json` still says `^8.3`. Check the domain's PHP version in Plesk and use the matching CLI binary (e.g. `/opt/plesk/php/8.4/bin/php`) for cron and Composer. |
| PHP extensions | `intl` (IDN domains), `openssl`, `curl`, `pdo_mysql`, `mbstring`, `sockets` not required | `stream_socket_client` is used for DNS (UDP/TCP 53) and TLS inspection. |
| Outbound network | TCP 80/443 to monitored sites, UDP+TCP 53 to the configured resolvers (default 1.1.1.1, 8.8.8.8, 9.9.9.9), HTTPS to `data.iana.org` and registry RDAP servers | If the VPS firewall restricts egress, open these or set `SERVERSCOPE_DNS_NAMESERVERS` to an allowed resolver. |
| Node | **not** needed on the server | `public/build` (Vite output) and `public/css/serverscope.css` are committed. Rebuild locally with `npm ci && npm run build` and commit when JS/CSS in `resources/` changes. |
| Mail | Real SMTP settings | `MAIL_MAILER=log` only writes to `storage/logs`. Alerts, password resets and invitations need SMTP. |

## 2. Deploy steps

1. Back up the database (Plesk → Databases → Export, or `mysqldump`).
2. Pull the branch through the Plesk Git integration.
3. `composer install --no-dev --optimize-autoloader` with the PHP 8.4 CLI.
4. `php artisan migrate --force` — all migrations are additive (new tables
   and nullable/defaulted columns); see section 4.
5. Add the new `.env` values you want (section 5); defaults are safe.
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
7. Ensure `storage/` and `bootstrap/cache/` are writable by the subscription user.
8. Set up the scheduled task (section 3) if it does not exist yet.
9. Verify (section 6).

## 3. Scheduler and queue

Plesk → **Scheduled Tasks** → add, run every minute as the subscription user:

```
cd /var/www/vhosts/serverscope.nl/<app-dir> && /opt/plesk/php/8.4/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs:

| Command | Frequency | Purpose |
|---|---|---|
| `serverscope:check-agents` | every minute | mark silent servers offline, agent-offline incidents |
| `serverscope:dispatch-domain-checks` | every minute | queue due HTTP/TLS/DNS/RDAP checks (atomic claim, no duplicates) |
| `serverscope:escalate-incidents` | every minute | escalate unacknowledged incidents |
| `queue:work --stop-when-empty --max-time=55` | every minute | process queued checks, notifications, webhooks |
| `serverscope:prune` | daily 03:17 | delete history older than the configured retention (disabled by default) |

All use `withoutOverlapping`. With `QUEUE_CONNECTION=database` (recommended
on Plesk) the scheduler-driven worker is enough for small to medium
installations. With many domains or short intervals, run a supervised
`php artisan queue:work --sleep=3 --tries=1 --max-time=3600` instead and set
`SERVERSCOPE_SCHEDULER_QUEUE_WORKER=false`. Failed jobs are visible with
`php artisan queue:failed`.

Do **not** use `QUEUE_CONNECTION=sync` in production: checks would run inside
web requests and webhook retries are disabled.

## 4. Database changes (all additive)

| Migration | Change |
|---|---|
| `2026_10_25_120000_add_default_agent_offline_alert_rule` | inserts one enabled "Agent offline" rule (5 min) |
| `2026_10_26_100000_add_management_fields_to_servers_table` | `environment`, `notes`, maintenance window, `deleted_at` |
| `2026_10_26_100100_create_audit_logs_table` | audit log |
| `2026_10_26_110000_create_incidents_tables` | `incidents`, `incident_events`, `alerts.incident_id`, `alert_rules.severity` (agent rules → critical) |
| `2026_10_26_120000_create_domains_tables` | `domains`, `domain_checks`, `dns_snapshots`, `dns_changes` |
| `2026_10_26_130000_create_notification_tables` | `notifications`, `notification_logs`, user notification preferences (admins: e-mail + in-app on) |
| `2026_10_26_140000_add_two_factor_columns_to_users_table` | encrypted 2FA columns |
| `2026_10_26_150000_create_api_tokens_table` | personal API tokens |
| `2026_10_26_160000_create_webhooks_tables` | webhook endpoints and deliveries |
| `2026_10_26_170000_create_status_pages_tables` | status pages and components |
| `2026_10_27_100000_add_type_to_webhook_endpoints_table` | `webhook_endpoints.type` (default `generic`) and `min_severity` (nullable) for Discord |

Every migration has a `down()`. Rolling back the domain/incident migrations
deletes the data stored in those tables, so restore from the backup instead
of rolling back once the features are in use.

**APP_KEY**: 2FA secrets, webhook secrets and Discord webhook URLs are encrypted with `APP_KEY`.
Never rotate `APP_KEY` without re-encrypting, or 2FA users are locked out
and webhooks need new secrets.

## 5. Environment variables

All optional; the defaults are shown.

| Variable | Default | Meaning |
|---|---|---|
| `SERVERSCOPE_DISPLAY_TIMEZONE` | `Europe/Amsterdam` | timezone for displayed and entered times (storage stays UTC) |
| `SERVERSCOPE_ALERT_MAIL` | `true` | global switch for notification e-mail (in-app unaffected) |
| `SERVERSCOPE_SCHEDULER_QUEUE_WORKER` | `true` | start a short-lived worker from the scheduler |
| `SERVERSCOPE_DNS_NAMESERVERS` | `1.1.1.1,8.8.8.8,9.9.9.9` | resolvers for DNS checks and SSRF resolution |
| `SERVERSCOPE_DNS_TIMEOUT` | `3` | seconds per resolver |
| `SERVERSCOPE_MONITOR_PORTS` | `80,443` | ports monitoring/webhooks may connect to |
| `SERVERSCOPE_ALLOW_PRIVATE_TARGETS` | `false` | **keep false in production** |
| `SERVERSCOPE_ALLOWED_CIDRS` | – | explicit exceptions (e.g. a monitored private network) |
| `SERVERSCOPE_MAX_REDIRECTS` / `_MAX_RESPONSE_BYTES` | `5` / `1048576` | HTTP check limits |
| `SERVERSCOPE_REQUESTS_PER_IP_PER_MINUTE` | `30` | outbound requests per target IP |
| `SERVERSCOPE_MIN_INTERVAL` / `SERVERSCOPE_MAX_DOMAINS` | `1` / `1000` | abuse limits |
| `SERVERSCOPE_SSL_INTERVAL` / `_DNS_INTERVAL` / `_EXPIRY_INTERVAL` | `360` / `15` / `1440` | minutes between slower checks |
| `SERVERSCOPE_DNS_ALERT_TYPES` | `A,AAAA,CNAME,MX,NS,CAA` | record types that notify on change |
| `SERVERSCOPE_INCIDENT_REOPEN_MINUTES` | `60` | recurrence window that reopens an incident |
| `SERVERSCOPE_NOTIFY_REOPEN_COOLDOWN` / `_DNS_COOLDOWN` | `15` / `30` | notification suppression (minutes) |
| `SERVERSCOPE_MAX_MAILS_PER_HOUR` | `30` | per-user e-mail cap (falls back to in-app) |
| `SERVERSCOPE_ESCALATE_CRITICAL_MINUTES` / `_WARNING_MINUTES` | `15` / `120` | escalation delay for unacknowledged incidents |
| `SERVERSCOPE_ESCALATION_EMAILS` | – | extra escalation recipients (comma separated) |
| `SERVERSCOPE_REPORT_TARGET` | `99.9` | default availability target in reports |
| `SERVERSCOPE_API_RATE_LIMIT` | `120` | API requests per minute per token |
| `SERVERSCOPE_WEBHOOKS_ALLOW_HTTP` | `false` | allow non-TLS webhook URLs |
| `SERVERSCOPE_NOTIFY_CHAT_COOLDOWN` | `15` | minutes before the same incident event is posted to Discord again (0 = off) |
| `SERVERSCOPE_DOCS_URL` | `https://info.serverscope.nl/nl/docs` | documentation link in the sidebar and setup screens (empty = hidden) |
| `SERVERSCOPE_RETAIN_*_DAYS` | – (keep forever) | retention for metrics, domain checks, notification logs, read notifications, audit logs |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | proxies in front of PHP (Plesk nginx); read at boot, not from the config cache |

Also set `SESSION_SECURE_COOKIE=true`, `APP_ENV=production`, `APP_DEBUG=false`
and a real `APP_URL=https://serverscope.nl`.

## 6. Verification after deploy

```sh
php artisan about                    # PHP version, environment, cache status
php artisan schedule:list            # five scheduled commands
php artisan migrate:status           # all migrations ran
php artisan serverscope:check-agents
php artisan serverscope:dispatch-domain-checks && php artisan queue:work --stop-when-empty
php artisan queue:failed             # should be empty
```

In the UI: add a domain, wait two minutes, and confirm HTTP/TLS/DNS results
on its page; send a test notification from **Profiel**; check
**Meldingen → Verzendgeschiedenis** for "Verzonden".

## 7. Backups and recovery

- Database: daily dump (Plesk backup manager or `mysqldump --single-transaction`),
  kept off the server. Test a restore into a staging database periodically.
- Files: `.env` (contains `APP_KEY`) must be backed up separately and securely.
  Code is in Git; `storage/` only holds logs and cache.
- Agent tokens and API tokens are stored hashed; after a restore that loses
  recent tokens, rotate them from the UI.

## 8. Manual actions still required

1. Confirm/upgrade the Plesk PHP version to 8.4 (web and CLI) before
   `composer install`; consider changing `"php": "^8.3"` to `"^8.4"` in
   `composer.json` so the mismatch fails early.
2. `composer update livewire/livewire` on PHP 8.4 (medium advisory
   PKSA-xhv3-nr94-h8db; Livewire is installed but not used — removing it is
   also an option).
3. Configure SMTP and the scheduled task.
4. Decide on retention periods (`SERVERSCOPE_RETAIN_*_DAYS`).
5. Ask administrators to enable two-factor authentication (Profiel).
6. Install/upgrade the agent on each server using `agent/README.md`.
