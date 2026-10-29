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
- One-command server enrollment (phase 2): same branch, on top of phase 1
  in `main`. Baseline 449 of 450 PHPUnit tests (only the known TLS test
  fails) and 36 agent tests; afterwards 482 of 483 (the 449 plus 33 new) and 39 agent tests,
  plus a container test of `install.sh` on Ubuntu 22.04, Ubuntu 24.04 and
  Debian 12. See §6.
- Signed agent releases and updates from the dashboard (phase 3): branch
  `claude/admiring-darwin-oiqti5`. Baseline 482 of 483 PHPUnit tests (only
  the known TLS test fails) and 39 agent tests; afterwards 533 of 534 (the
  483 plus 51 new; still only the known TLS test fails) and 43 agent tests,
  plus container tests on Ubuntu 22.04, Ubuntu 24.04 and Debian 12 and one
  with real systemd. See §7.

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
| One-command server enrollment (Linux) | COMPLETE (phase 2) | one-time enrollment tokens per team, `install.sh`, agent package with SHA-256; see §6. Not signed yet |

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

## 6. One-command server enrollment (phase 2)

### What it does
**Servers → Server toevoegen → Linux**: a team administrator creates a
one-time enrollment token and gets one command:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://<app>/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh
```

The script asks for the token (hidden input) or reads it from
`SERVERSCOPE_ENROLL_TOKEN`, installs the agent, exchanges the token for a
normal agent credential and waits for the first sample. The page shows a
live status ("Wachten op agent…" → "Verbonden" with a link). The manual
registration and every existing agent keep working unchanged;
`POST /api/agent/metrics` is unchanged. Details: `agent/README.md`.

### Security decisions
- **Enrollment tokens** (`enrollment_tokens`): `sse_` + 48 hex characters,
  only the SHA-256 is stored, bound to the creating team and user, valid 60
  minutes (`SERVERSCOPE_ENROLLMENT_TTL_MINUTES`), invalid after one
  successful use, revocable, at most 10 pending per team
  (`SERVERSCOPE_ENROLLMENT_MAX_PENDING`). Only team administrators create
  or revoke them; other teams' tokens do not exist (404).
- **`POST /api/agent/enroll`**: the team comes only from the token. A
  single conditional `UPDATE` claims the token inside a transaction; the
  server and credential are created through the existing
  `ServerRegistrationService`/`AgentCredentialService`; a failure rolls back
  and leaves the token usable. Every unusable token (unknown, malformed,
  expired, used, revoked) gets the same `401 Ongeldig of verlopen
  koppeltoken.` Input (hostname, OS, architecture, agent version) is
  validated strictly. Rate limit: 10 attempts per minute per address
  (`SERVERSCOPE_ENROLLMENT_ATTEMPTS_PER_MINUTE`) plus 300 per minute
  platform-wide.
- **`GET /api/agent/self` / `POST /api/agent/revoke`**: for `install.sh`
  (first sample, uninstall); a credential can only revoke itself. Both use
  their own rate limit counters per credential (plain `throttle:N,1` shares
  one counter per IP across routes).
- **No secrets in logs**: tokens and credentials are never written to
  Laravel logs, the audit log, error responses or the script's output
  (tested, including validation errors and exceptions). Audit entries:
  `enrollment.created`, `enrollment.used` (in the token's team, with the
  new server), `enrollment.revoked`, `agent_credential.self_revoked`.
- **HTTPS only**: the command and the script use `--proto '=https'
  --tlsv1.2`; the URL is always `APP_URL`, never the request's Host header.
  With an http `APP_URL` the page shows an error and `/install.sh` answers
  503. `SERVERSCOPE_ENROLLMENT_ALLOW_INSECURE_URL` allows http for local
  testing only and is ignored in production.
- **Agent package**: `/agent/serverscope-agent-<version>.tar.gz` and
  `.sha256` are built from `agent/` (reproducible: fixed order, times and
  owners), cached under a hash of the source files (changed code without a
  new version number gives a new package) and written atomically. **The
  checksum comes from the same server as the package: it protects against
  corrupt downloads, not against a compromised ServerScope server.** Signed
  releases are phase 3.
- **The agent executes nothing on request of the server**; the agent code is
  unchanged. Dependencies come from the distribution's signed packages
  (`python3-psutil`, `python3-requests`, `python3-dotenv`), not from PyPI.
- **New servers** start as "pending" and raise no "agent offline" incident
  until their first sample (tested).

### Tests
- PHPUnit: 33 new tests (`tests/Feature/Enrollment`), passing on SQLite and
  MySQL 8.0; agent: 3 new tests that the agent reads `<install dir>/.env`.
- Real concurrency on MySQL: 6 rounds of 20 parallel enroll requests with
  one token against 4 PHP workers: always exactly one 201, one server, one
  credential.
- `install.sh` passes shellcheck (style level).
- `scripts/e2e/install-test.sh`: the documented command in clean
  **Ubuntu 22.04, Ubuntu 24.04 and Debian 12** containers (amd64) against a
  local ServerScope over HTTPS: install via the hidden prompt, server online
  in the token's team, `.env` layout/owner/mode/format, user without shell,
  the agent running as `serverscope`, running twice (afterwards `.env` is
  still 640, a planted leftover `.env.XXXXXX` is removed, and a watcher
  polling every 50 ms never saw a `.env*` readable for others),
  re-enrolling after a revoked credential (new server, via the environment
  variable), `--uninstall` (credential revoked via the API, only own files
  and user removed), a manual installation left alone, an invalid token,
  and no token or credential in the output: 39 of 39 checks on all three.
  Against the previous `install.sh` the three `.env*` checks fail, so they
  catch the issue. Ubuntu 20.04 is refused with a clear message. In this
  test environment Debian's package mirrors are only reachable through an
  HTTPS proxy, so for Debian the test points apt (only apt) at that proxy.
- `install.sh` never makes `.env*` readable for others: the recursive
  `chmod` of the installation directory skips `.env*`, which then get
  `o-rwx` (`.env`: 640), and temporary `.env.XXXXXX` files left by an
  interrupted run are removed (they may hold a credential).

### Not tested / limitations
1. **systemd** itself was not exercised: the containers run without
   systemd; a stand-in starts the agent exactly like the (unchanged) unit.
   The unit's sandboxing options are therefore not re-tested here (they are
   unchanged from the manual installation).
2. **arm64** was not tested (amd64 containers only).
3. Production on Plesk (APP_URL, reverse proxy, TLS certificate) was not
   tested.
4. Re-enrolling after a revoked credential creates a **new** server; the
   old one stays (with its history) until it is deleted in the dashboard.
5. `POST /api/agent/metrics` keeps its existing `throttle:60,1`, which is one
   counter per IP address: many agents behind one NAT address share it
   (pre-existing; unchanged so the contract stays exactly the same).
6. The checksum is not a signature (see above); signed releases and
   automatic updates are phase 3 (done, see §7).

## 7. Signed agent releases and updates from the dashboard (phase 3)

### What it does
A team administrator sees per server the installed and the newest agent
version and the state of the updater, and clicks **Bijwerken** (or
**Alles bijwerken** on the server list, or turns on **automatisch
bijwerken** per server, off by default). The updater on that server
installs the release only after checking its signature with the key it
stored at installation, and switches back when the new version sends no
sample. Platform administrators see the releases and the number of
servers per version (**Agentreleases**). Key handling: `docs/RELEASE_KEYS.md`;
for server owners: `agent/README.md`.

### Design
- **Signature**: Ed25519 over a strict line-based manifest (format,
  product, version, file name, SHA-256, size, commit, date,
  `min_updater_version`, `key_id`); the manifest covers the tarball.
  Made on the owner's Mac with OpenSSL 3 (`scripts/release/`), checked
  with `openssl pkeyutl -rawin` on customer servers and with PHP sodium in
  ServerScope. minisign was rejected: it is not available on Ubuntu 22.04.
  Verified in containers: OpenSSL 3.0.2 (Ubuntu 22.04, main), 3.0.13
  (Ubuntu 24.04), 3.0.x (Debian 12) accept a valid signature and refuse a
  changed manifest; PHP sodium accepts the OpenSSL signature.
- **Private key**: only offline (encrypted PKCS#8, scrypt). Not in the
  repository, the tests, the logs or any server; tests generate key pairs
  at runtime. `*.pem` and `release-key*` are git-ignored.
- **Releases on the server**: table `agent_releases` (platform-wide),
  filled only by `serverscope:release:import <bundle> --admin=<platform
  administrator>` after checking signature, manifest, checksum and
  contents (only the expected regular files; no links, pax headers,
  absolute paths or `..`). `serverscope:release:withdraw`, `:list`. No web
  route adds releases. Nothing is built on the server any more:
  `AgentPackage` is no longer used by any route (a test proves it); the
  class stays for its existing tests.
- **Downloads**: `/agent/releases/<v>/manifest`, `manifest.sig`,
  `serverscope-agent-<v>.tar.gz` (public, only imported and not
  withdrawn). The phase 2 URLs `/agent/serverscope-agent-<v>.tar.gz(.sha256)`
  serve the signed tarball of an imported version and answer
  404 "Deze versie wordt niet meer aangeboden; download install.sh
  opnieuw" otherwise.
- **Update API** (agent credential, own limiter `agent-update` 30/min per
  credential): `GET /api/agent/update?installed=&updater=&state=` (strictly
  validated; unknown or extra values are refused with 422 and not stored)
  answers only `{request_id, version}` or null; `POST
  /api/agent/update/report` with a status and a fixed reason code, only
  for the server's own requests (others: 404), only valid transitions
  (otherwise 409). `GET /api/agent/self` gained `agent_version`.
  `POST /api/agent/metrics` is unchanged (tested: route, controller,
  middleware).
- **Update requests** (`agent_update_requests`, per team): pending,
  in progress, succeeded, rolled back, refused, failed, expired (after 24 h,
  on withdrawal, or when the version already runs). At most one open
  request per server. Automatic updates never retry a version that failed
  on that server. Audit entries: `agent_update.requested` (who, from →
  to, source), `.started`, `.succeeded`, `.rolled_back`, `.rejected`,
  `.failed`, `.expired`, `.auto_enabled`, `.auto_disabled`. Release
  imports and withdrawals are platform actions without a team: they are
  recorded on the release row (`imported_by`, `withdrawn_by`, reason) and
  in the Laravel log.
- **Layout on customer servers**: `/opt/serverscope-agent/releases/<v>/`,
  the symlink `serverscope_agent` → `releases/<v>/serverscope_agent`
  (switched atomically with `mv -T`), and a `.env` link to `../../.env` in
  every release directory, because agent 0.2.0 resolves its own path and
  would otherwise look for `releases/<v>/.env`. Agent 0.3.0 finds `.env`
  without resolving the symlink. The agent unit is unchanged
  (`ReadOnlyPaths` only needs to read through the links).
- **Updater** (`serverscope-updater`, Bash, root, `serverscope-updater.timer`
  every 5 minutes, hardened unit: `ProtectSystem=strict`, writable only
  `/opt/serverscope-agent` and its own state, `NoNewPrivileges`, limited
  capabilities and address families). Order: local emergency switch (no
  network at all) → check-in → signature with the local key(s) → manifest
  (same rules as ServerScope; the verifying key must be the manifest's
  `key_id`; version must be the offered one; `min_updater_version`) →
  SHA-256, size and contents → version strictly higher than the installed
  one and the highest ever installed here, and not failed here before →
  install next to the current version → switch → restart → wait (at least
  3 minutes) for ServerScope to report the new version → otherwise switch
  back. The version check against the offered number is also done before
  downloading (to avoid needless downloads); the decisive check uses the
  signed manifest.
- **install.sh**: verifies the newest signed release with the key written
  in the script and prints its fingerprint; stores it in
  `/etc/serverscope/release.pub` (root:root 644) and refuses to replace a
  different stored key without `--replace-release-key`; asks "Updateprogramma
  installeren? [J/n]" (default yes; `--updates=on|off`,
  `SERVERSCOPE_UPDATES`; when updates were disabled earlier the default is
  no); `--disable-updates` / `--enable-updates`; converts 0.2.0
  installations; never downgrades. Without an imported release it stops
  before changing anything.

### Owner-approved changes to existing tests
- `AgentDownloadTest::test_download_and_checksum_match`: rewritten for the
  new behaviour of the phase 2 URLs (signed tarball and checksum from the
  manifest for an imported version, 404 with an explanation for 0.2.0 and
  unknown versions, invalid versions 404, withdrawn 404). Reason: the
  server no longer builds unsigned packages.
- Only the agent version number `0.2.0` → `0.3.0`:
  `AgentDownloadTest::test_the_package_contains_exactly_the_agent_files_in_the_installed_layout`
  (line `assertSame('0.3.0', $package->version())`) and
  `agent/tests/test_collector.py` (`result["agent_version"] == "0.3.0"`).
- `RouteTenancyTest::PUBLIC_ROUTES` lists the three new public download
  routes (the mechanism that test asks for).

### Also fixed
- `config/serverscope.php` defined `'agent'` twice; the second replaced
  the first, so `latest_version` and `known_checksums` never worked
  ("verouderd" was never shown). Merged, with a test. The newest imported
  release now counts as the latest version.
- The `agent-self` and `agent-revoke` limiters (phase 2) were meant to
  count per credential, but the throttle middleware runs before
  `AuthenticateAgent`, so they fell back to the IP address: all servers
  behind one address shared one counter. They are now keyed by a hash of
  the presented token, with a ceiling of 600/min per address.

### Security review: an attacker who fully controls the ServerScope server
Assumption: the attacker controls the code, database, configuration,
storage, TLS certificate and every response of ServerScope, but not the
offline private key and not the customer server itself.

| What the attacker tries | Why it fails (or what is left) |
|---|---|
| Push own code through the updater | The updater installs only a manifest whose Ed25519 signature verifies against a key in `/etc/serverscope/` (root:root, written at installation; neither ServerScope nor the agent user can write there). Without the private key no valid signature can be made. The tarball must match the SHA-256 and size in that signed manifest. Tested: other key, changed manifest, changed tarball → refused, nothing changed (container test D/E). |
| Make the updater run something else | The server's answer is reduced to an integer request id and a version matching `^\d+\.\d+\.\d+$`; everything else is ignored. URLs are built locally from the local `SERVERSCOPE_URL` (root-owned `.env`) and fixed paths; https only. No value from the server is evaluated; JSON is parsed by Python's `json`. Before the signature is checked, only three small downloads are handled (4 KB, 512 bytes, 10 MB, each cut off hard). |
| Install an old, vulnerable release (validly signed) | Refused: the version must be higher than the installed one and than the highest ever installed on that server (`/var/lib/serverscope-updater/highest-version`, root 700). Tested with an older and the same version (F). |
| Mix parts of different releases | The signed manifest binds product, format, version, file name, checksum, size and key ID; the version must equal the offered one; the verifying key must be the one the manifest names. |
| Unsafe archive contents | Only after the checksum matches the signed manifest; even then only the expected regular files are accepted (no links, devices, `..`, absolute paths). |
| Abuse the emergency switch | `/etc/serverscope/updates-disabled` is checked first, before any network traffic (tested: zero requests reach the server, H), again just before switching, and by the unit (`ConditionPathExists`). The server cannot remove it. |
| Fake success or failure | Claiming failure only makes the updater switch back to the previous (signed) version; claiming success keeps the new (signed) version. A version that failed is never retried automatically. Effect: at most a restart of the agent per published release. |
| Withhold updates (freeze) | Possible: a compromised server can simply never offer an update. Not preventable by design; servers keep running their current signed version. |
| Through the agent itself | The agent (unprivileged, read-only directory) executes nothing the server sends; unchanged from phase 2. |
| **New installations** | **Possible.** install.sh comes from the server and contains the public key, so a compromised server can give a new installation its own key and code. The same holds when an owner runs install.sh again (for example to add the updater to a 0.2.0 installation): running a script from a server as root means trusting that server. The script refuses a different key than the stored one unless `--replace-release-key` is given, but a malicious script would not do that check. Mitigation: compare the fingerprint (printed by the script, published in `agent/README.md`). |
| Dashboard / platform accounts | A team administrator can only request signed releases for the team's own servers (other teams 404, members 403). A platform administrator account cannot import releases from the web (artisan only, and the server still cannot sign). |

Conclusion: with only the ServerScope server, an attacker cannot install
code of their own on EXISTING customer servers that have the updater. The
residual risks are new installations or re-runs of install.sh (trust on
first use, documented in `agent/README.md`), withholding updates, and
agent restarts. With the private key AND the server, an attacker could
install code: the key must stay offline (`docs/RELEASE_KEYS.md` §4).

### Tests
- PHPUnit: 51 new tests (`tests/Unit/Releases`, `tests/Feature/Releases`,
  `tests/Feature/AgentConfigTest.php`): manifest and signature (valid,
  other key, changed manifest, malformed signature, no trusted key, wrong
  `key_id`, strict parsing, archive contents); import (valid, invalid
  signature, other key, changed tarball, changed manifest, extra/missing
  files, duplicate version, only platform administrators, no web route,
  withdraw, numeric ordering); downloads (only imported and not withdrawn,
  old URLs, install.sh embeds the newest release and its key, no route uses
  `AgentPackage`); update API (own server only, other server's request
  404, strict check-in validation with nothing stored, report validation,
  transitions, disabled updater, automatic updates, withdrawn/stale
  requests, credential required, own rate limit per credential, existing
  agent API unchanged); dashboard (team administrator of the own team,
  another team 404, member 403, update all, automatic setting, audit log,
  refusals, platform overview with counts only); migrations up/down/up;
  `scripts/release/` end to end with a key made during the test (keygen,
  build, verify, import; refusal of a dirty tree, an untagged commit, a
  commit not equal to origin/main, and LibreSSL); shellcheck of the
  updater and the release scripts. All pass on SQLite; the migrations also
  ran up, down and up again on MariaDB 10.11 (not MySQL 8.0).
- pytest: 4 new tests for the release layout: agent 0.2.0 code finds its
  `.env` through the release link (and does not without it), 0.3.0 finds it
  without the link, and after switching releases.
- `scripts/e2e/update-test.sh` (Ubuntu 22.04, Ubuntu 24.04, Debian 12,
  amd64): 52 of 52 checks on each. The ServerScope side runs behind
  `evil_proxy.py`, which plays a compromised server. Covered: install
  without a release changes nothing (no packages, files, user or server);
  agent 0.2.0 installed with the phase 2 code keeps reporting after the
  upgrade of ServerScope; running install.sh again converts it (same
  server and credential, the question with Enter = yes, fingerprint
  shown, `release.pub` root:root 644, updater root 755, agent cannot write
  its files or the key, `.env` 640); a successful update 0.3.0 → 0.3.1;
  refused: changed tarball (`bad_checksum`), other key and changed
  manifest (`bad_signature`), an older and the same version (`downgrade`);
  roll back when the new version sends no sample (`no_sample`), agent 0.3.1
  reports again, the failed version is not retried (`already_failed`);
  the emergency switch: no request at all reaches the server while an
  update is offered, and after `--enable-updates` the update succeeds
  (0.3.1 → 0.3.2, only current and previous release kept); uninstall
  removes everything including the updater.
- `scripts/e2e/systemd-test.sh` (Ubuntu 24.04 with systemd as PID 1, privileged
  container): install.sh with real systemd, agent unit and updater timer
  active, an update through the real hardened `serverscope-updater.service`
  (including `systemctl restart` from inside the sandbox), no credential in
  the journal, `--disable-updates` stops the timer and the unit does not
  start while the switch exists. `systemd-analyze security`: updater 3.7
  ("OK"), agent 6.3 ("MEDIUM", unchanged unit).
- Found and fixed by these tests: the updater's lock descriptor was
  inherited by the restarted agent (later runs were locked out), and
  downloads are now cut off hard even when the server sends no length.

### Not tested / limitations
1. **Signing on macOS** was not run (no Mac here). The scripts were
   checked for bash 3.2 (the macOS default) by syntax and the array
   handling in a bash 3.2 container, and run end to end with OpenSSL 3
   and bash 5 on Linux. The LibreSSL refusal was tested with a stand-in.
2. **The timer firing on its own** (after 5 minutes) was not waited for;
   it was verified as active and scheduled, and the service it starts was
   run for real. **arm64** was not tested (amd64 containers only).
3. **Production on Plesk** (import as root, file ownership, config cache)
   was not tested; see `docs/FASE3_STAPPENPLAN.md`.
4. **MySQL 8.0**: the migrations ran on MariaDB 10.11 only.
5. `scripts/e2e/install-test.sh` (phase 2) was not changed and was not run
   again: with a terminal, install.sh now asks the update question, which
   that test does not answer. `update-test.sh` covers its main paths; the
   phase 2 checks for re-enrolment after a revoked credential, an invalid
   token, a manual installation and the `.env` watcher were not re-run.
6. **Trust on first use**: new installations (and re-runs of install.sh)
   trust the key in the script from the server (see the security review).
7. Updating the **updater itself** is not built: it is installed and
   replaced only by install.sh; `min_updater_version` is reserved for that.
8. Key rotation is designed but only minimally built (several trusted keys
   on both sides); see `docs/RELEASE_KEYS.md` §6.
9. A compromised server can **withhold** updates and can cause at most one
   agent restart per published release.
