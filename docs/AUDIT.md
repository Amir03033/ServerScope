# ServerScope — Product Audit & Roadmap

Audit date: 2026-10-25. Scope: the ServerScope Laravel application in this
repository (not `info.serverscope.nl`). Every status below is based on reading
the code and running the test suites in a local container; nothing was
verified against the production server at serverscope.nl.

## 1. What the application is today

ServerScope is a **single-tenant, agent-based server metrics dashboard**:

- A Python agent (`agent/`) collects CPU, load, memory, swap, disks, network
  counters and uptime with `psutil` and POSTs them over HTTPS every 60 s to
  `POST /api/agent/metrics`, authenticated with a per-server bearer token
  (stored as a SHA-256 hash).
- The Laravel app (Laravel 13, Livewire 4 installed but unused, Blade views,
  Chart.js via Vite) stores samples, shows a dashboard and a server detail
  page, and evaluates admin-defined threshold alert rules (CPU / RAM / disk)
  when samples arrive.
- Users: login only (no self-registration). Admins (`users.is_admin`,
  created with `php artisan serverscope:create-admin`) can register servers
  and manage alert rules. Every authenticated user can see every server.

There is **no** domain, DNS, SSL, HTTP-uptime, incident, status-page, API-token,
team, or reporting functionality. The UI is in Dutch.

## 2. Feature inventory (deliverable A)

Legend — COMPLETE: implemented and verified by tests/run; PARTIAL; BROKEN;
MISSING; UNVERIFIED. Priority P1 (core) … P4 (hardening).

### 2.1 Server monitoring

| Feature | Status | Evidence | Missing | Files | Prio | Next action |
|---|---|---|---|---|---|---|
| Agent metric ingestion | COMPLETE | Validated payload, 10-min/30-s timestamp window, per-server unique `collected_at` → 409, throttle 60/min; 20+ API tests pass | Agent-side buffering/retry of failed samples | `Api/MetricSampleController.php`, `routes/api.php`, `tests/Feature/MetricSampleApiTest.php` | — | Keep |
| Agent authentication | COMPLETE | 64-hex token, hashed, `revoked_at`/`expires_at` honoured; tests | — | `Middleware/AuthenticateAgent.php` | — | Keep |
| Agent credential revocation / rotation | PARTIAL | Columns exist and are enforced | No UI/command to revoke, rotate, or issue a second token | `AgentCredential.php` | P1 | Add admin actions + artisan command |
| Python agent | PARTIAL | Collector/client/config with 24 passing pytest tests; HTTPS enforced | No systemd unit/install docs, samples lost on failure, version hardcoded, no clock-skew handling (server rejects samples >30 s in the future) | `agent/` | P2 | Add install docs + unit file, local retry queue |
| Server registration | PARTIAL | Admin-only JSON endpoint + dashboard modal, token shown once; tests | — | `ServerRegistrationController.php`, `ServerRegistrationService.php` | — | Keep |
| Server edit / delete / maintenance mode / environments / groups | MISSING | No routes or columns | Everything | — | P1 | Add CRUD + `environment`, `maintenance_until` |
| Dashboard | PARTIAL | Real data, server list, charts for the most recently seen server; tests | Only one server's charts; no domains/incidents; no search/filter | `DashboardController.php`, `dashboard.blade.php` | P2 | Extend after layout extraction |
| Server detail page + history charts | COMPLETE (fixed) | Was **BROKEN** for 7d/30d (see §4.1); fixed and tested | — | `ServerDetailController.php` | — | Done |
| Online/offline status | COMPLETE (fixed) | Was **BROKEN**: `servers.status` stayed `online` forever. Now persisted by the scheduler | — | `AgentHeartbeatService.php` | — | Done |
| Agent disconnection alerts | COMPLETE (new) | `agent_offline` rule type, scheduled check, recovery on next sample; 11 tests | Per-server overrides | `AgentHeartbeatService.php`, `CheckAgentHeartbeats.php` | — | Done |
| Resource threshold alerts (CPU/RAM/disk) | PARTIAL | Duration window with gap detection, per-mountpoint disk alerts, row locking; tests | Load/network/swap metrics, warning vs critical levels, recovery hysteresis (resolves on first good sample), per-server scoping (rules are global) | `AlertDetectionService.php`, `AlertRuleController.php` | P2 | Add severity + recovery threshold |
| Process/service health, filesystem health | MISSING | Agent doesn't collect | — | — | P3 | — |
| Data retention | MISSING | `metric_samples` grows ~525k rows/server/year | Pruning or downsampling | — | P2 | Add opt-in prune command |

### 2.2 Alerts, incidents, notifications

| Feature | Status | Evidence | Missing | Files | Prio | Next action |
|---|---|---|---|---|---|---|
| Alert list (active/resolved/disabled) | COMPLETE | Filters, pagination; tests | Acknowledge, assign, notes | `AlertController.php` | — | Becomes the base for incidents |
| Alert deduplication | COMPLETE | One active alert per server+rule+metric+dimension, under locks | DB-level unique guard (only lock-based) | `AlertDetectionService.php` | P4 | — |
| Incident lifecycle (ack/investigating/resolved/closed), assignment, audit trail, MTTA/MTTR | MISSING | — | Everything | — | P1 | Build on `alerts` (see roadmap) |
| Manual incidents | MISSING | — | — | — | P2 | — |
| E-mail notifications | COMPLETE (new) | Trigger + recovery only, queued after commit with retries, admins only; 8 tests + E2E run with the database queue | Per-user preferences, delivery history | `AlertObserver.php`, `AlertStateChanged.php` | — | Done |
| In-app / webhook / Slack / Discord notifications | MISSING | — | — | — | P2/P3 | — |
| Escalation, notification schedules, maintenance windows | MISSING | — | — | — | P2 | — |

### 2.3 Domain monitoring (section 3 of the brief)

| Feature | Status | Notes | Prio |
|---|---|---|---|
| Domain CRUD, pause/resume, detail page | MISSING | No model/table/routes | P1 |
| HTTP/HTTPS availability, response time, status codes, redirects, keyword | MISSING | Requires SSRF-safe HTTP client (see §4.3) | P1 |
| SSL/TLS certificate monitoring | MISSING | — | P1 |
| DNS record monitoring & change history | MISSING | PHP `dns_get_record` lacks timeouts/resolver control; consider a resolver library | P1 |
| Domain expiration (RDAP/WHOIS) | MISSING | RDAP via IANA bootstrap; many ccTLDs (incl. some `.nl` data) limit fields/rate | P1 |
| Domain health dashboard | MISSING | — | P2 |

### 2.4 Platform

| Feature | Status | Evidence / gap | Prio |
|---|---|---|---|
| Login / logout | COMPLETE | Session regenerate, dual throttling (route 10/min + per email/IP 5/min); tests | — |
| Password reset, email verification, 2FA, profile, session management | MISSING | Only `serverscope:create-admin` CLI | P2 |
| Authorization | PARTIAL | Inline `is_admin` checks in controllers, no policies; all users see all servers (acceptable for single-tenant) | P3 |
| Teams / tenant isolation | MISSING | No ownership columns. Needed only if customers will use it | P3 |
| Public API, API tokens, webhooks | MISSING | Only the agent API exists | P3 |
| Status pages | MISSING | — | P3 |
| Uptime / SLA reports, exports | MISSING | — | P2 |
| Scheduler | COMPLETE (new) | Was MISSING (only the `inspire` stub). Now runs agent checks + optional queue worker | — |
| Queue / failed jobs | PARTIAL | `jobs`/`failed_jobs` tables exist; worker strategy now defined; no failed-job alerting | P2 |
| Audit log | MISSING | — | P2 |
| Tests | PARTIAL | 81 PHPUnit tests + 24 pytest tests pass; no browser tests; no CI workflow | P4 |

## 3. Development roadmap (deliverable B)

Complexity: S (< 1 day), M (1–3 days), L (3+ days).

### Phase 1 — core monitoring

| # | Item | Depends on | Acceptance criteria | Cx |
|---|---|---|---|---|
| 1.1 | ✅ Fix server detail long-range charts | — | 30d chart spans the full range, includes latest sample | S |
| 1.2 | ✅ Scheduler + agent offline detection | — | Silent server → `offline` + one alert; recovers on next sample | S |
| 1.3 | ✅ E-mail on alert trigger/recovery | 1.2 | One mail per transition, queued, after commit | S |
| 1.4 | Extract a shared Blade layout (sidebar, styles, nav) | — | Every page uses `<x-layouts.app>`; no visual change; existing tests pass | M |
| 1.5 | Server management: edit, delete (with confirmation), environment label, maintenance mode, credential revoke/rotate | 1.4 | Admin-only, validated, tested; maintenance suppresses alerts | M |
| 1.6 | SSRF-safe outbound check client | — | Blocks private/loopback/link-local/metadata IPs incl. after DNS resolution and on every redirect; pins resolved IP; port allow-list; tests | M |
| 1.7 | Domain model + CRUD + pause/resume | 1.4 | Unique normalised hostname, IDN support, validated | M |
| 1.8 | HTTP + SSL checks (scheduled, per-domain interval ≥ 1 min) | 1.6, 1.7 | Stores status, latency, cert issuer/subject/validity; consecutive-failure threshold; tests with faked transport | L |
| 1.9 | Generalise alerts into incidents (subject = server or domain, fingerprint, ack/resolve, events, auto-reopen window) | 1.8 | No duplicate incidents for repeated failures; MTTA/MTTR from stored timestamps | L |
| 1.10 | DNS monitoring + change history | 1.7 | Records snapshot per type, diff produces event | M |
| 1.11 | Domain expiry via RDAP | 1.7 | Stores source + fetched_at; "unknown" when unsupported; thresholds 30/14/7/3/1 | M |

### Phase 2 — operational visibility
Dashboard overhaul, uptime reporting (gaps count as *unknown*, not *up*),
maintenance windows, notification preferences per user, in-app notifications,
data retention, password reset / 2FA, audit log.

### Phase 3 — advanced
More agent metrics (services, processes), public status pages (explicit
public fields only), versioned API + tokens (Sanctum), signed webhooks with
retries and delivery log, teams/tenancy if customers are planned, incident
correlation.

### Phase 4 — hardening
Policies instead of inline checks, security headers/CSP, CI (tests + Pint),
backup/restore drill, performance (metric rollups), production readiness review.

## 4. Technical findings (deliverable C)

### 4.1 Broken functionality (fixed in this branch)
1. **Server detail charts showed the wrong data for 7d/30d.** The query
   ordered ascending with `limit(2000)`, so at one sample per minute the 7d
   and 30d views showed only the *oldest* ~33 hours and the network rate was
   computed against a stale sample. Fixed with even downsampling across the
   range (`ServerDetailController::downsample`).
2. **No offline detection.** Alerts were only evaluated when a sample
   arrived, so a server that went down raised nothing and `servers.status`
   remained `online`. Fixed (scheduled `serverscope:check-agents`).
3. **No scheduler or notifications at all.** Fixed for e-mail.

### 4.2 Architecture
- Every page is a standalone 250–1100 line Blade file with its own copy of
  the CSS, icon sprite and sidebar. Adding domain/incident pages without a
  shared layout would multiply this. Extracting a layout is roadmap item 1.4.
- `resources/views/welcome.blade.php` (references a non-existent `register`
  route) and `resources/views/servers/register-form.blade.php` (duplicate of
  the dashboard modal) are not used anywhere — dead code.
- Livewire 4 is installed but unused.
- Alert rules are global; there is no per-server/per-resource policy.
- `alerts.server_id` / `alert_rule_id` are non-nullable, so alerts cannot
  represent domain problems — the incident model (1.9) must generalise this.
- `/agent/ping` is registered in `routes/web.php` (session middleware) rather
  than `routes/api.php`.
- The code base is not Pint-clean (pre-existing style deviations); there is
  no CI.

### 4.3 Security
- Good: hashed agent tokens, HTTPS-only agent, strict ingestion validation,
  CSRF on all forms, login throttling, token shown once, no `{!! !!}` with
  user data (only internally generated SVG).
- No user-supplied URLs are fetched today, so there is **no SSRF exposure
  yet** — but domain/HTTP/webhook features must not be built on plain
  `Http::get($url)`. Required controls: resolve DNS first and reject private,
  loopback, link-local (incl. `169.254.169.254`), CGNAT, multicast and IPv6
  ULA ranges; connect to the vetted IP (prevents DNS rebinding); re-validate
  every redirect hop; restrict ports (80/443 by default); cap response size,
  timeouts and frequency.
- No security headers (CSP, HSTS, X-Frame-Options) are set by the app;
  check whether Plesk/nginx adds them. Set `SESSION_SECURE_COOKIE=true` in
  production.
- No audit log of admin actions (rule changes, server registration).
- `composer audit --locked` on the committed lock reports one **medium**
  advisory: `livewire/livewire` 4.3.0–4.4.7 (authorization bypass in
  `#[Authorize]`, PKSA-xhv3-nr94-h8db). The app defines no Livewire
  components, so exposure is minimal, but run
  `composer update livewire/livewire` on PHP 8.4 (or remove Livewire if it
  will not be used). Not changed here because the lock cannot be resolved
  correctly on this container's PHP 8.3. `npm audit`: 0 vulnerabilities.

### 4.4 Deployment risks
- **PHP version mismatch:** `composer.json` declares `php: ^8.3`, but
  `composer.lock` contains Symfony 8.1 packages requiring **PHP ≥ 8.4.1**.
  `composer install` fails on PHP 8.3. Confirm the Plesk PHP version for the
  domain *and* for the CLI used by cron (`/opt/plesk/php/8.4/bin/php`), or
  bump the constraint in `composer.json` to `^8.4` so the mismatch is explicit.
- `public/build` is committed although `.gitignore` lists it. Frontend
  changes require running `npm run build` and committing the output (none
  of the changes in this branch touch JS/CSS).
- Nothing ran in the background before this branch; the cron entry below is
  now **required** for offline detection and e-mail.

## 5. Production requirements (after this branch)

1. Run migrations: `php artisan migrate --force` (adds one row: the default
   "Agent offline" alert rule, 5 minutes, enabled — no schema changes).
2. Plesk → Scheduled Tasks, every minute, as the subscription user:
   ```
   cd /var/www/vhosts/serverscope.nl/<app-dir> && /opt/plesk/php/8.4/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   (adjust the path and PHP binary to the actual installation).
3. Configure real `MAIL_*` settings (the default `log` mailer only writes to
   `storage/logs`). Admin accounts receive the alerts.
4. Queue: with `QUEUE_CONNECTION=database` the scheduler starts a short-lived
   worker every minute (`SERVERSCOPE_SCHEDULER_QUEUE_WORKER=true`, default).
   If you run a supervised `php artisan queue:work`, set it to `false`.
5. Optional: `SERVERSCOPE_ALERT_MAIL=false` disables alert e-mail.
6. Run `php artisan config:cache` after changing `.env`.

## 6. Verification performed

- Baseline before changes: 61 PHPUnit tests passed, 24 pytest tests passed.
- After changes: **81 PHPUnit tests pass (255 assertions)**, 24 pytest pass.
- The new long-range chart test was run against the old controller and
  failed as expected (2000 vs 2500 samples), then passed with the fix.
- End-to-end smoke run on a file SQLite DB: stale server → `check-agents`
  marked it offline and created one alert → one job queued →
  `queue:work --stop-when-empty` delivered the mail (log mailer).
- Limitation: the container has PHP 8.3, while the committed lock needs
  8.4.1. Tests ran against a PHP 8.3-compatible dependency set resolved into
  `vendor/` only (Laravel 13.38, Symfony 7.x); the committed `composer.lock`
  was not modified. Tests should be re-run on PHP 8.4 with the real lock.
- Not done: MySQL-specific behaviour (row locks are no-ops on SQLite), the
  Plesk deployment itself, frontend build, browser testing.
- The repository's CLAUDE.md asks to install Laravel Boost; this was skipped
  because it would rewrite `composer.json`/`composer.lock` (and, on PHP 8.3,
  downgrade the lock) — that should be a deliberate decision by the owner.
