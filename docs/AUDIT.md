# ServerScope — Product Audit, Status & Roadmap

- Original audit: 2026-10-25 (61 tests, server-metrics dashboard only).
- Status update: 2026-10-26, branch `claude/hopeful-euler-bbinww`
  (289 PHPUnit tests and 24 agent tests passing).
- Monitoring & intelligence upgrade: branch `claude/cool-curie-fk2smd`
  (407 PHPUnit tests and 32 agent tests passing). Details, remaining work
  and limitations: [UPGRADE_PLAN.md](UPGRADE_PLAN.md); report method:
  [REPORTS.md](REPORTS.md).
- Multi-tenancy phase 1 (teams): branch `claude/festive-goldberg-l278l4`,
  PHP 8.4. Baseline 406 of 407 PHPUnit tests (the known
  `TlsInspectorTest::test_expired_certificate` fails because of the test
  itself on PHP 8.4/OpenSSL 3) and 36 agent tests; afterwards the same
  406 plus 43 new tenancy tests pass on SQLite; on MySQL 8.0 see §5
  (four older tests are MySQL-incompatible, also before this work).

Scope: the ServerScope Laravel application (not `info.serverscope.nl`).
Statuses are based on code plus automated tests and local runs in a
container; nothing was verified on the production VPS. Deployment
instructions are in [DEPLOYMENT.md](DEPLOYMENT.md), the API in [API.md](API.md).

Legend: **COMPLETE** = implemented and verified by tests (and a local run
where noted) · **PARTIAL** · **MISSING** · **UNVERIFIED**.

## 1. Feature inventory

### Servers

| Feature | Status | Evidence / notes |
|---|---|---|
| Metric ingestion, agent auth | COMPLETE | unchanged; deleted servers' agents are rejected |
| Server list/search/filters, create, edit, environments, notes | COMPLETE | `ServerController`, `ServerManagementTest` |
| Soft delete + restore + permanent delete (name confirmation) | COMPLETE | history kept on soft delete; credentials revoked |
| Maintenance windows (scheduled/immediate) suppress alerts | COMPLETE | heartbeat + metric alerts suppressed; recovery still tracked |
| Credential issue/revoke/rotate, token shown once | COMPLETE | `AgentCredentialService`; rotation invalidates old tokens |
| Offline detection, status, last seen | COMPLETE | scheduler; `Server::displayStatus()` single source |
| Historical charts | COMPLETE | 7d/30d downsampling fix; charts were empty in production because the Vite build was not committed — fixed |
| Resource alerts with severity | PARTIAL | CPU/RAM/disk/agent-offline; no load/network/swap rules, no separate warning+critical thresholds per rule, recovery on first good sample |
| Process/service health | COMPLETE | agent 0.2.0 `SERVERSCOPE_SERVICES`; `ServerHealthTest` |
| Agent packaging | COMPLETE | systemd unit + install guide (`agent/README.md`); no local buffering of failed samples |

### Domains

| Feature | Status | Evidence / notes |
|---|---|---|
| CRUD, pause/resume, soft delete/restore, IDN, duplicates, validation | COMPLETE | `DomainManagementTest` |
| HTTP/HTTPS checks (status, latency, redirects, timeouts, TLS errors, content) with failure/recovery thresholds | COMPLETE | `DomainMonitorsTest`; live run against github.com |
| SSL/TLS (issuer, subject, SAN, validity, mismatch, chain, self-signed, expiry thresholds, change detection) | COMPLETE | unit tests with real TLS handshakes against generated certificates; live inspection worked |
| DNS (A, AAAA, CNAME, MX, TXT, NS, CAA), failure detection, snapshots, change history, NS changes, propagation comparison | COMPLETE | wire-format tests with hand-built packets and a forked local nameserver. Live: only A queries could be verified (sandbox DNS answers A only) |
| Domain expiry via RDAP (bootstrap, registrable domain, 429, no invented dates) | PARTIAL | fixture-based tests only: the sandbox blocks IANA/registry hosts, so live RDAP is **UNVERIFIED**. Registries that publish no expiration date (e.g. some ccTLDs) show "niet beschikbaar" |
| SSRF protection for all outbound checks | COMPLETE | `IpPolicy`, `TargetGuard`, `SafeHttpClient` (IP pinning verified live), 16 attack-scenario tests |
| Domain health dashboard, detail pages, history | COMPLETE | |

### Incidents, notifications, reporting

| Feature | Status | Evidence / notes |
|---|---|---|
| Incidents from server alerts and domain checks, fingerprints, unique active incident, reopen window, recovery | COMPLETE | `IncidentManagementTest`, `DomainMonitorsTest` |
| Lifecycle, assignment, comments, timeline, manual incidents, filters, MTTA/MTTR | COMPLETE | |
| Incident correlation across resources | COMPLETE | via confirmed dependencies only, never by timing alone; `IncidentCorrelationTest` |
| E-mail + in-app notifications, preferences, delivery log, suppression, mail cap | COMPLETE | `NotificationsTest` (real delivery through array mailer) |
| Escalation of unacknowledged incidents | COMPLETE | per-severity delay, optional extra recipients |
| DNS/SSL/domain-expiry notifications | COMPLETE | via incidents (threshold escalation) and DNS change notification |
| Slack/Discord | PARTIAL | via webhooks (relay); no native integration |
| Webhooks (signed, retries, delivery log, SSRF-safe) | COMPLETE | `WebhooksTest` |
| Uptime/downtime/response/incident reports, periods, targets, CSV | COMPLETE | missing data lowers coverage, never counts as uptime |
| Public/private status pages | COMPLETE | only approved data published; no custom-domain support (use a proxy) |

### Platform

| Feature | Status | Evidence / notes |
|---|---|---|
| Shared layout, responsive pages, empty/loading/error states | COMPLETE | old pages overflowed at 390 px; fixed |
| Dashboard with servers, domains, incidents, expiries, DNS changes, trends, global search | COMPLETE | |
| Password reset, 2FA (TOTP + recovery codes), profile, password change | COMPLETE | `AuthSecurityTest` incl. RFC 6238 vectors |
| User management (invite, roles) | COMPLETE | per team: owner / admin / member |
| Teams / multi-tenant isolation | COMPLETE (phase 1) | every customer is a team that only sees its own data; see §5. Registration and invitations exist but are **off** |
| API tokens + REST API v1 | COMPLETE | `ApiTest` |
| Audit log | COMPLETE | admin page, secrets redacted |
| Data retention | COMPLETE | opt-in, disabled by default |
| Security headers, trusted proxies | COMPLETE | |
| Email verification | MISSING | users are created by admins (invitation proves the address) |
| Session management UI (list/revoke sessions) | MISSING | password change logs out other devices |

## 2. Roadmap status

Done in this round, in order: shared layout → server management →
SSRF-safe monitoring layer → domain monitoring (HTTP/TLS/DNS/RDAP) →
incident management → dashboard → notifications → reports → retention and
audit log → password reset and 2FA → API → webhooks → status pages → user
management → security headers and agent packaging.

Remaining, by priority:

| # | Item | Why | Size |
|---|---|---|---|
| 1 | Run on PHP 8.4 + MySQL and deploy (see DEPLOYMENT.md) | tests ran on PHP 8.3/SQLite only | S |
| 2 | Verify RDAP live for the TLDs in use (.nl, .com, …) | sandbox could not reach registries | S |
| 3 | Resource rules: warning + critical thresholds, load/swap/network, recovery hysteresis, per-server scoping | alert flapping and global rules | M |
| 4 | Agent: local buffering/retry, service/process checks | lost samples during outages | M |
| 5 | Recurring maintenance windows and a maintenance history (for reports) | only one window per resource | M |
| 6 | Native Slack/Discord channels, notification schedules (quiet hours) | convenience | M |
| 7 | Session management UI, email verification | account hygiene | S |
| 8 | Teams phase 2: domain names unique per team (**required before public registration**), per-team escalation contacts, team settings UI | customers sign up themselves | M |

## 3. Technical findings

### Fixed in this round
1. Server detail charts showed only the oldest data for 7d/30d.
2. No offline detection or background processing.
3. **Production charts were empty**: `public/build/manifest.json` referenced
   JS/CSS that were never committed (`/public/build` was gitignored).
4. Pages overflowed horizontally on phones.
5. `AlertDetectionServiceTest` failed intermittently around second boundaries.

### Architecture
- Monitoring code lives in `app/Monitoring` (DNS client, IP policy, target
  guard, safe HTTP client, TLS inspector) and `app/Services/{Domains,
  Incidents,Notifications,Reports}`; controllers stay thin.
- Alerts remain the per-rule state for servers; incidents are the
  human-facing record for every resource type.
- No new Composer dependencies were added (DNS client and TOTP are
  implemented in-house and tested) because `composer.lock` cannot be safely
  re-resolved without PHP 8.4.
- The older pages (dashboard, server detail, alerts, alert rules) keep their
  page-specific CSS inside the shared layout; new pages use the shared
  component classes.

### Security
- Outbound requests: only public unicast IPs, allowed ports, http/https,
  no credentials in URLs, IP pinned per request, every redirect re-validated,
  response size capped, per-IP rate limit, environment proxies bypassed.
- Secrets: agent/API tokens hashed; 2FA and webhook secrets encrypted with
  `APP_KEY`; tokens/secrets shown once; audit log redacts secrets.
- Remaining: medium Livewire advisory in `composer.lock` (unused package);
  CSP allows inline scripts/styles (views rely on them).

### Deployment risks
- PHP ≥ 8.4.1 required by the lock file (see DEPLOYMENT.md §1).
- The scheduled task must exist, or no checks run.
- MySQL-specific behaviour (row locks, `DATE()` grouping) is covered by code
  paths but was only executed on SQLite here.

## 4. Decisions for the owner
- **Tenancy**: decided — multi-tenant with teams; phase 1 is implemented
  (§5). Public registration stays off until the blockers in §5 are fixed.
- **Livewire**: update or remove.
- **Retention**: choose periods; reports can only cover retained data.

## 5. Multi-tenancy (teams), phase 1

### Model
- `teams` (one `is_default` team holds all data from before multi-tenancy),
  `team_user` with a role per membership: **owner**, **admin**, **member**.
  Roles are the only source of permissions; `users.is_admin` is kept as a
  mirror of the role in the current team (role → column) and grants nothing.
- `users.current_team_id` is verified on every request (membership); a user
  who is no longer a member is signed out (web) or gets 403 (JSON).
- **Platform administrator** (`users.is_platform_admin`, set only with
  `php artisan serverscope:create-admin --platform`): platform-wide system
  status, nothing else. No access to data or configuration of any team.
- `team_id` on: servers, domains, monitored_pages, alert_rules, alerts,
  incidents, predictions, resource_dependencies, webhook_endpoints,
  status_pages, api_tokens, audit_logs, notification_logs. All other tables
  inherit the team through their parent (metric samples, checks, DNS
  history, certificates, credentials, services, events, impacts, deliveries,
  components).

### How isolation is enforced
- A tenant context is set only by HTTP middleware (session, API token,
  agent credential), before route model binding: an ID of another team is a
  404, never a 403.
- A global scope on the 13 team-owned models (and a parent scope on child
  models that are addressed directly) applies only within a tenant.
  Scheduler commands and queued jobs always run without a tenant and
  process every team; where the team matters (notification recipients,
  alert rules, webhooks, correlation, dependencies, escalation) it is
  filtered explicitly from the record itself.
- New records take their team from their parent/subject or the tenant.
  Outside automated tests a record without a determinable team throws
  `MissingTeamException` (also for audit log entries); there is no silent
  fallback to the default team.
- API tokens belong to one team and stop working (401) when their owner
  leaves that team. Agents are unchanged: the credential determines the
  server, the server the team.
- Cached data with team content is keyed per team (dashboard trends,
  system-health summary); other cache keys contain a resource or token id.

### Known limitations and blockers (must be fixed before public registration)
1. **Domain names are unique platform-wide** (unique index on
   `domains.name`, kept because phase 1 only adds schema). A customer could
   "claim" and thereby block a domain of another customer. Another team gets
   a neutral message that does not reveal the owner, but the block itself
   remains. **Fix before `SERVERSCOPE_REGISTRATION_ENABLED` is turned on**
   (unique per team, or domain ownership verification).
2. User e-mail addresses are unique platform-wide: inviting or registering
   an address that already has an account tells the inviter that it exists.
3. Status page slugs are unique platform-wide (they are public URLs).
4. Escalation contacts from `SERVERSCOPE_ESCALATION_EMAILS` only receive
   incidents of the default team; other teams have no escalation contacts
   yet (their members still receive escalations).
5. `max_domains` applies per team; there is no per-team plan or limit UI.
6. Team management UI is minimal: team switcher, members page per team;
   creating teams is `php artisan serverscope:create-team`.
7. In-app notifications (Laravel `notifications` table) belong to a user,
   not a team: a user removed from a team keeps the notifications they
   already received.
8. **Invitations add an existing account without its consent.** With
   `SERVERSCOPE_TEAM_INVITATIONS_ENABLED` an administrator adds an existing
   account to their team directly. **Fix before enabling invitations**: it
   must become an invitation the recipient accepts, and the inviter must
   not learn whether the e-mail address already has an account (same
   answer for new and existing addresses).
9. **Registration has no e-mail verification.** Anyone can sign up with
   someone else's address. **Fix before enabling registration**: verify
   the address before the account can be used.

### Flags (both off by default)
Do not enable registration before blockers 1, 2 and 9 are fixed, nor
invitations before blockers 2 and 8 are fixed.
- `SERVERSCOPE_REGISTRATION_ENABLED`: `/register` creates an account with
  its own team (otherwise 404).
- `SERVERSCOPE_TEAM_INVITATIONS_ENABLED`: an administrator can add an
  existing account to their team by e-mail address.

### Verified on MySQL 8.0 (local container, never production)
- All 43 new tests pass on MySQL 8.0.46, including the migration tests
  (backfill, NOT NULL, rollback of all six steps, running them again).
- Of the older tests, four do not pass on MySQL **on the base commit
  either** (verified on `6b59d08`); they are unrelated to teams:
  - `AgentHeartbeatTest::test_default_agent_offline_rule_is_installed_by_migration`
    hangs forever: it runs `migrate:fresh` inside its own open
    `RefreshDatabase` transaction (MySQL metadata lock). Excluded from the
    MySQL run with `--exclude-filter`.
  - `ServerManagementTest::test_admin_updates_server_and_changes_are_audited`
    and `WebsitePagesUiTest::test_admin_adds_several_pages_with_relative_paths`:
    MySQL JSON columns return object keys in a different order.
  - `PageDiscoveryTest::test_rediscovery_does_not_duplicate_and_auto_monitor_approves`.
  - Plus the known `TlsInspectorTest::test_expired_certificate`.
- The `down()` of the older migration `2026_10_28_100000` fails on MySQL
  (drops an index a foreign key still needs). Rolling back the six tenancy
  migrations is not affected; rolling back further is.

### Migration duration (MySQL 8.0, container, measured)
Data set: 1 000 000 alerts, 1 000 000 audit log rows, 1 000 000
notification log rows, 200 000 incidents, 50 000 pages, 1 000 domains,
500 servers, 50 users.

| Step | Duration |
|---|---|
| 1 teams and memberships | 0.3 s |
| 2 default team + memberships | 0.01 s |
| 3 team_id on servers, domains, pages, rules, alerts | 1 min 2 s – 1 min 9 s |
| 4 team_id on incidents, predictions, dependencies | 16 – 18 s |
| 5 team_id on webhooks, status pages, tokens, audit log, notification log | 1 min 46 s – 1 min 56 s |
| 6 NOT NULL | 33 – 34 s |
| **Total** | **3 min 40 s – 4 min** |
| Rollback of all six steps | 39 s |

Roughly one minute per million rows on the large tables; MySQL rebuilds
those tables (adding a foreign key), so writes to them wait. Run the
migration in maintenance mode (`php artisan down`).
