# ServerScope — Deployment and operations (Plesk)

This describes what production needs after the changes on branches
`claude/hopeful-euler-bbinww` and `claude/cool-curie-fk2smd` (monitoring &
intelligence upgrade, see [UPGRADE_PLAN.md](UPGRADE_PLAN.md)). Nothing here has been run on the production
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
| `serverscope:dispatch-page-checks` | every minute | queue due website page checks (max. 20 per website and 500 in total per run, configurable) |
| `serverscope:escalate-incidents` | every minute | escalate unacknowledged incidents (grouped incidents escalate via their root) |
| `serverscope:analyze` | hourly at :07 | anomaly detection and capacity forecasts (reads hourly rollups for aggregated hours) |
| `serverscope:rollup` | hourly at :12 | aggregate closed hours of raw metric samples into hourly and daily rollups (see §9) |
| heartbeat (closure) | every minute | lets **Beheer → Systeemstatus** report whether the scheduler runs |
| `queue:work --stop-when-empty --max-time=55` | every minute | process queued checks, notifications, webhooks |
| `serverscope:prune` | daily 03:17 | delete history older than the configured retention (disabled by default) |

All use `withoutOverlapping`. With `QUEUE_CONNECTION=database` (recommended
on Plesk) the scheduler-driven worker is enough for small to medium
installations. With many domains or short intervals, run a supervised
`php artisan queue:work --sleep=3 --tries=1 --max-time=3600` instead and set
`SERVERSCOPE_SCHEDULER_QUEUE_WORKER=false`. Failed jobs are visible with
`php artisan queue:failed` and on **Beheer → Systeemstatus**.

Website pages add load: every monitored page is one queued HTTP request per
interval, and discovery runs (`RunPageDiscovery`, up to 300 requests, 300 s
time-out) run on the same queue. With hundreds of pages run a supervised
worker; give it `--timeout=300` so discovery is not killed. Page checks and
discovery are never retried automatically (the next interval is the retry),
so a failing website cannot cause a job storm.

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
| `2026_10_28_100000_add_correlation_and_dependencies` | `incidents.started_at/parent_id/correlation`, `incident_impacts`, `resource_dependencies`, `alert_rules.recovery_samples` (default 1 = old behaviour), `alerts.recovery_streak`, `notifications_enabled` and `sla_target` on servers and domains |
| `2026_10_28_110000_create_website_pages_tables` | `domains.discovery_settings`, `monitored_pages`, `page_checks`, `page_changes`, `page_discovery_runs` |
| `2026_10_28_120000_add_service_checks_and_extended_metrics` | nullable disk I/O, network error, process-count columns on `metric_samples`; `servers.agent_checksum/predictive_alerts_enabled/anomaly_sensitivity`; `service_checks`, `service_check_events` |
| `2026_10_28_130000_create_predictions_table` | `predictions` |
| `2026_10_28_140000_create_ssl_certificates_table` | `ssl_certificates` (certificate history) |
| `2026_11_15_100000_create_favorites_table` | `favorites` (personal favourite servers/domains; unique per user and resource). Additive |
| `2026_11_14_100000_create_incident_analyses_table` | `incident_analyses` (AI Incident Investigator: status, evidence snapshot, validated result, usage; unique `incident_id` + `evidence_hash`). Additive |
| `2026_11_13_100000_create_incident_learnings_table` | `incident_learnings` (Incident Memory: one row per incident, unique `incident_id`, `version` for concurrent edits). Additive |
| `2026_11_09_100000_create_metric_rollups_table` | `metric_rollups` (hourly/daily aggregates, unique per server/period/bucket/metric/dimension); nullable `servers.metrics_rolled_up_until` and `servers.cpu_count`. Additive; `migrate:rollback --step=1` drops only the rollups (raw samples are untouched) |

The five 2026-10-28 migrations only add tables and nullable/defaulted
columns; existing rows and history are untouched. To roll them back:
`php artisan migrate:rollback --step=5` (drops the new tables, so page
history, dependencies, predictions and certificate history are lost —
restore the backup instead once they are in use). Existing severities are
unchanged; `high` is a new value between `warning` and `critical`.

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
| `SERVERSCOPE_RETAIN_*_DAYS` | – (keep forever) | retention for metrics, domain checks, notification logs, read notifications, audit logs, and (new) `PAGE_CHECKS`, `PAGE_CHANGES`, `DISCOVERY_RUNS`, `SERVICE_EVENTS`, `PREDICTIONS` (resolved only) |
| `SERVERSCOPE_ESCALATE_HIGH_MINUTES` | `30` | escalation delay for high-severity incidents |
| `SERVERSCOPE_CORRELATION_WINDOW_MINUTES` | `30` | a newly detected root cause adopts dependent incidents opened at most this long before it |
| `SERVERSCOPE_MAX_PAGES_PER_WEBSITE` | `1000` | monitored + discovered pages per website |
| `SERVERSCOPE_DISCOVERY_DEFAULT_PAGES` / `_MAX_PAGES` | `50` / `1000` | safe default and hard limit for discovery |
| `SERVERSCOPE_DISCOVERY_MAX_REQUESTS` / `_MAX_SITEMAPS` / `_DELAY_MS` / `_TIMEOUT` / `_MAX_DURATION` | `300` / `25` / `250` / `10` / `240` | discovery budget per run |
| `SERVERSCOPE_PAGE_CHECKS_PER_WEBSITE` / `_PER_RUN` | `20` / `500` | page checks queued per scheduler run |
| `SERVERSCOPE_SIGNIFICANT_CHANGE_RATIO` | `0.10` | share of changed text that makes a content change "significant" |
| `SERVERSCOPE_PAGE_GROUP_MIN` | `2` | pages failing for the same shared cause before one website incident is used |
| `SERVERSCOPE_AGENT_LATEST_VERSION` | `0.2.0` | older agents are flagged |
| `SERVERSCOPE_AGENT_CHECKSUMS` | – | SHA-256 checksums of released agent builds; others are flagged |
| `SERVERSCOPE_PREDICTIVE` / `_PREDICTIVE_ALERTS` | `true` / `true` | run the analysis / open incidents for near-term capacity forecasts |
| `SERVERSCOPE_PREDICTIVE_HORIZON_DAYS` | `7` | forecast incidents only within this horizon |
| `SERVERSCOPE_ANOMALY_INCIDENTS` | `false` | also open incidents for anomalies (they are always shown) |
| `SERVERSCOPE_RETAIN_HOURLY_ROLLUPS_DAYS` / `_DAILY_ROLLUPS_DAYS` | `400` / – (keep forever) | retention of metric rollups, independent of `SERVERSCOPE_RETAIN_METRICS_DAYS` (raw samples) |
| `SERVERSCOPE_ROLLUPS_QUEUE` | `false` | `serverscope:rollup` dispatches one job per server instead of running inline (only with supervised workers) |
| `SERVERSCOPE_ROLLUPS_BACKFILL_DAYS` | `35` | how far back the first rollup run per server goes (older: `serverscope:rollup --from=`) |
| `SERVERSCOPE_ROLLUPS_READ` | `true` | analytics read rollups for aggregated hours (`false` = raw samples only, the old behaviour) |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | proxies in front of PHP (Plesk nginx); read at boot, not from the config cache |

Also set `SESSION_SECURE_COOKIE=true`, `APP_ENV=production`, `APP_DEBUG=false`
and a real `APP_URL=https://serverscope.nl`.

## 6. Verification after deploy

```sh
php artisan about                    # PHP version, environment, cache status
php artisan schedule:list            # includes serverscope:rollup (hourly at :12)
php artisan migrate:status           # all migrations ran
php artisan serverscope:check-agents
php artisan serverscope:dispatch-domain-checks && php artisan queue:work --stop-when-empty
php artisan queue:failed             # should be empty
php artisan serverscope:rollup       # first run backfills up to 35 days per server; safe to repeat
php artisan serverscope:prune --dry-run   # shows raw and rollup retention before anything is deleted
```

In the UI: open **Beheer → Systeemstatus** (scheduler, queue and overdue
checks should be green after two minutes); add a domain, wait two minutes, and confirm HTTP/TLS/DNS results
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
6. Install/upgrade the agent on each server using `agent/README.md`
   (0.2.0 adds service checks: set `SERVERSCOPE_SERVICES`).
7. Record which websites run on which servers (**Afhankelijkheden**, or
   confirm the suggestions on the domain pages); without dependencies no
   cross-resource incident grouping happens.

## 9. Metric rollups and worker requirements

**Storage.** Raw samples (`metric_samples`, one row per server per agent
interval, about 25–50 MB per server per month at 60 s) are aggregated by
`serverscope:rollup` into `metric_rollups`: per server, hour and day, one
row per metric and disk mount with `count`, `sum`, `min` and `max`. Hourly
rows take roughly 1.5 MB per server per month. Rollups have their own
retention (`SERVERSCOPE_RETAIN_HOURLY_ROLLUPS_DAYS`, default 400 days;
daily rows are kept) and, per team, `serverscope.rollups.plan_retention_days`
(proposal: Free 90, Pro 730, Premium unlimited; the default team is
exempt). They are therefore kept when raw samples are pruned, e.g. with
`SERVERSCOPE_RETAIN_METRICS_DAYS=30`.

**How it runs.** An hour is aggregated once it is closed: 11 minutes after
its end, when the ingest API (which accepts samples up to 10 minutes old)
can no longer add to it. Writes are upserts on a unique key and a bucket is
never replaced by one built from fewer samples, so a repeated run, a
retried job or a recomputation with `--from` never creates duplicates or
shrinks history. `servers.metrics_rolled_up_until` advances per 24-hour
chunk; an interrupted run resumes there.

**First deploy.** After `migrate`, run `php artisan serverscope:rollup`
once by hand (or wait for :12). It aggregates at most
`SERVERSCOPE_ROLLUPS_BACKFILL_DAYS` (35) per server; for older history:
`php artisan serverscope:rollup --from=2026-01-01` (inline, any time,
repeatable). Until a server has rollups, analytics read raw samples as
before.

**Readers.** Anomaly detection and forecasts (`serverscope:analyze`) read
rollups for aggregated hours and raw samples only for the partial first
hour and the not yet aggregated hours, so their cost no longer grows with
14 days of raw rows per server. The server charts still read raw samples
(only the needed columns); reports count raw samples for coverage.

**Raw retention** now deletes per server through the
`(server_id, collected_at)` index instead of scanning `metric_samples` by
`collected_at` (which has no index of its own).

**Workers.** What runs where:

| Work | Where | Notes |
|---|---|---|
| Metric ingestion + alert evaluation | web request (sync) | Kept synchronous on purpose: rules need the sample just stored, and a queue would delay alerts by the worker cadence. Cost: one rule query plus one short transaction per rule and dimension. |
| Domain/page checks, discovery, notifications, webhooks | queue | unchanged |
| Rollups | scheduler, inline (default) or queue (`SERVERSCOPE_ROLLUPS_QUEUE=true`) | one unique job per server, 3 tries with backoff |
| Analysis (`serverscope:analyze`) | scheduler, inline | sequential; cheap now that it reads rollups. Candidate for per-server jobs above ~1,000 servers |
| Pruning | scheduler, daily | batched deletes |

The scheduler-driven worker (`queue:work --stop-when-empty --max-time=55`,
`SERVERSCOPE_SCHEDULER_QUEUE_WORKER=true`) is enough for small
installations. Use a supervised worker (`php artisan queue:work --sleep=3
--tries=1 --max-time=3600 --timeout=300`, and set
`SERVERSCOPE_SCHEDULER_QUEUE_WORKER=false`) when any of these hold: more
than about 100 servers or 500 monitored pages, `SERVERSCOPE_ROLLUPS_QUEUE`
is enabled, or **Beheer → Systeemstatus** shows a queue backlog. Page
checks are capped at `SERVERSCOPE_PAGE_CHECKS_PER_RUN` (500) per minute in
total; raise it together with worker capacity.


## 10. Remote probe (Global Pulse Lite)

One second monitoring location that re-checks a failing website before an
HTTP incident is opened. Without a probe nothing changes.

**Central server:** migrate, then register the probe once (prints the
credentials once; `--rotate` replaces the secret):

```
php artisan serverscope:probe:create probe-1 --location="Frankfurt"
```

The scheduler runs `serverscope:probe:expire` every minute.

**Probe host** (a small VPS in another network/region): the same code,
`composer install --no-dev`, and an `.env` with `APP_KEY` (its own),
`APP_ENV=production`, `CACHE_STORE=file`, `SERVERSCOPE_PROBE_URL=https://…`,
`SERVERSCOPE_PROBE_KEY`, `SERVERSCOPE_PROBE_SECRET`. No database, no web
server and no inbound ports are needed. Run `php artisan serverscope:probe:run`
as a supervised service (systemd, `Restart=always`); it polls every 30 s.
Outbound: HTTPS to ServerScope, TCP 80/443 to monitored sites, DNS.

**Behaviour.** At the failure threshold, with a probe that polled in the
last 3 minutes, a confirmation job is queued (one per website) and the
incident waits for it: probe fails too → critical incident ("confirmed");
probe reaches the site → warning incident ("main location only"),
rechecked every 10 minutes and raised to critical when confirmed; main
monitor recovered but probe fails → recorded only ("probe only"); no answer
within 120 s or no healthy probe → critical incident as before
("unavailable"). Alerts are therefore delayed by at most about two
minutes, and only while the probe is healthy.

**Security.** Requests are HMAC-SHA256 signed (method, path, timestamp,
nonce, body hash), refused outside ±5 minutes or with a reused nonce, and
rate limited. Jobs contain only URL, time-out and redirect setting; results
only status code, timing and an error code. The probe forces the SSRF rules
on (private, loopback, link-local/metadata, reserved addresses and
non-allowed ports are blocked, also after redirects), makes plain GET
requests with a 10 s cap, and accepts no inbound connections.

## 11. AI Incident Investigator

Off unless `OPENAI_API_KEY` **and** `SERVERSCOPE_AI_MODEL` are set (the
model is only named in `.env`). Without them the incident page says that
AI analysis is unavailable and nothing else changes.

| Variable | Default | Meaning |
|---|---|---|
| `OPENAI_API_KEY` | – | used only by the backend (`App\Services\Ai\OpenAiProvider`), never sent to browsers |
| `SERVERSCOPE_AI_MODEL` | – | an OpenAI model that supports Structured Outputs (`json_schema`) |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | API base URL |
| `SERVERSCOPE_AI_TIMEOUT` / `_MAX_OUTPUT_TOKENS` / `_MAX_INPUT_CHARS` | `45` / `1500` / `24000` | per-request limits |
| `SERVERSCOPE_AI_AUTO_MIN_SEVERITY` | `high` | automatic analysis for new root incidents of at least this severity (empty = manual only) |
| `SERVERSCOPE_AI_AUTO_DELAY` | `120` | seconds after opening, so confirmations and samples are in |
| `SERVERSCOPE_AI_MANUAL_COOLDOWN` | `10` | minutes between manual requests per incident |
| `SERVERSCOPE_AI_MAX_PER_TEAM_HOUR` | `20` | analyses per team per hour |

**Queue.** Analyses run as queued jobs (`QueueAutomaticIncidentAnalysis`,
`RunIncidentAnalysis`, 120 s time-out, no automatic retries). The
scheduler-driven worker handles them; with a supervised worker use
`--timeout` of at least 130 s. Incident creation and notifications never
wait for them.

**What is sent.** Only the incident's own team data, built by
`IncidentEvidence` from Time Machine (timeline, findings, hourly metric
summaries), Deploy Judge (deployments in the window), Global Pulse probe
results and Incident Memory (similar earlier incidents and their recorded
learnings). Text is shortened; credentials, tokens, private keys, e-mail
and IP addresses are redacted; URLs, configuration and environment values
are never included. The data is wrapped as untrusted input; the model has
no tools and cannot change anything. Answers are validated against a
strict schema; facts without valid evidence references and references to
incidents outside the evidence are dropped.

**Costs.** One analysis per incident and evidence snapshot (unique key),
a job that runs twice calls the provider once, manual requests are
admin-only with a cooldown, and a per-team hourly cap applies. Token usage
is stored per analysis (`input_tokens`, `output_tokens`). Failures store
only a kind (timeout, rate_limited, provider_error, invalid_response,
unavailable, queue_failure); prompts and answers are not logged.

AI output is labelled as hypotheses and never written to Incident Memory;
people record confirmed causes there themselves.

## 12. Error and maintenance pages

`resources/views/errors/{403,404,419,429,500,503}.blade.php` share one
self-contained layout (inline CSS, no Vite assets, no database, session
or JavaScript), so they render in maintenance mode and when the database
is down. Optional links: `SERVERSCOPE_PUBLIC_URL` (homepage, default
`https://serverscope.nl`, empty = hidden) and `SERVERSCOPE_STATUS_URL`
(public status page, hidden when empty).

Maintenance mode uses Laravel's mechanism unchanged:

```
php artisan down --retry=600            # 503 page + Retry-After: 600 ("back in about 10 minutes")
php artisan down --secret="<random>"    # bypass link for the operator; never shown on the page
php artisan up
```

Without `--retry` the page says that no return time has been announced; it
never shows a countdown. Production must keep `APP_DEBUG=false` so that the
500 page (and no exception details) is shown.

