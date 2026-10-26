# ServerScope — Monitoring & Intelligence upgrade: audit and plan

Date: 2026-10-26. Baseline: 300 PHPUnit tests passing (PHP 8.3, SQLite).

## 1. Audit summary (before this upgrade)

| Area | State | Notes |
|---|---|---|
| Architecture | Solid | Thin controllers, services under `app/Services`, SSRF-safe outbound layer in `app/Monitoring/Net`, incidents as the human-facing record for every resource type. Single-tenant: every signed-in user sees everything, `admin` gate for configuration. |
| Server agent | Partial | CPU, load, memory, swap, disks, network byte counters, uptime. No service/process checks, no disk I/O or network errors, no integrity information. |
| Domain monitoring | Complete for one URL per domain | HTTP (status, latency, redirects, content), TLS, DNS (+change history), RDAP expiry, thresholds. No per-page monitoring, no discovery. |
| Incidents | Complete per resource | Fingerprints, one active incident per problem (unique index), reopen window, lifecycle, assignment, notes, escalation, MTTA/MTTR. **No correlation across resources, no dependency model, no MTTD.** Severity scale info/warning/critical only. |
| Alerting | Mostly complete | E-mail, in-app, signed webhooks, Discord; delivery log; flapping cooldown; mail cap; escalation. Server alerts recover on the first good sample (no recovery confirmation). No per-resource notification switch, no incident-aware suppression. |
| Status pages | Complete | Only explicitly published components. |
| Security | Good | Hashed tokens, encrypted secrets, 2FA, audit log, rate limits, SSRF layer with IP pinning and per-hop redirect validation. |
| Reports | Partial | Domain uptime/coverage, incidents, CSV. No per-resource targets, no MTTD, no page-level data, no PDF. |
| UI language | Dutch only | All views have hard-coded Dutch strings. |

Weaknesses found: alert recovery flaps on a single good sample; no way to
express "this website runs on that server"; simultaneous outages produce
one notification per resource; the API has no idempotency for writes; the
monitoring system does not report on its own health (scheduler, queue).

## 2. Prioritised plan (implemented in this order)

1. **Incident intelligence** — four-level severity (Low/Medium/High/Critical,
   stored as info/warning/high/critical for compatibility), dependency
   mapping (confirmed vs inferred), correlation engine with recorded
   reasons, incident-aware notification suppression, per-resource
   notification switch, MTTD, recurrence statistics, mandatory resolution
   summary, recovery confirmation for server alert rules.
2. **Website monitoring** — monitored pages per domain with their own
   checks, history and alert settings; safe discovery (robots.txt,
   sitemaps, sitemap indexes, internal links) with approval; page-level
   correlation; content change monitoring; website overview.
3. **Server and service monitoring** — service checks reported by the
   agent, extended metrics, agent health diagnosis.
4. **Predictive monitoring** — explainable anomaly detection and capacity
   forecasts.
5. **Security/configuration** — certificate history, header drift.
6. **Reports, API, self-monitoring, retention, documentation.**

Each step is additive (new tables/columns only), covered by tests, and
keeps existing API contracts.

## 3. Delivery status (2026-10-26)

Verified by 407 PHPUnit tests (300 before) and 32 agent tests (24 before),
run on PHP 8.3 / SQLite in a container. Nothing was run on production.

### Implemented

| Area | What | Tests |
|---|---|---|
| Severity | Low/Medium/High/Critical everywhere (stored `info/warning/high/critical`) | `IncidentCorrelationTest` |
| Correlation | Groups only via confirmed dependencies or the same resource, and only when the root's type can explain the failure (agent/service/HTTP/page outage → availability, invalid certificate → TLS, DNS → DNS). Reverse adoption within a window. Reason, path and evidence stored; both timelines updated; manual detach. Simultaneity alone never groups. | `IncidentCorrelationTest`, `PageMonitoringTest` |
| Dependencies | Confirmed edges (UI + API, cycle check), structural edges (page→website, service→server), inferred suggestions (domain IP = server IP) shown but never used for grouping, impact panel | `IncidentCorrelationTest`, `MonitoringApiTest` |
| Lifecycle | MTTD (`started_at`), recurrence statistics, mandatory resolution summary in the UI, recovery confirmation for server alert rules (`recovery_samples`) | `IncidentCorrelationTest` |
| Alerting | Incident-aware suppression (grouped incidents notify via their root, also on Discord), per-resource notification switch (servers, domains, pages, services), escalation skips grouped incidents | `IncidentCorrelationTest`, `PageMonitoringTest` |
| Website pages | Per-page interval, thresholds, status codes, response-time threshold, redirect validation, keyword presence/absence, header assertions, TLS state, alert settings, history; failure types kept apart | `PageMonitoringTest` |
| Discovery | robots.txt, sitemaps, sitemap indexes, gzip, manual sitemaps, internal links; normalisation and dedupe, scope/patterns/subdomain opt-in, robots rules, page/request/sitemap/depth/time limits, trap detection, approval with re-validation, safe defaults with explicit opt-in for broader coverage; failures recorded without touching existing pages | `PageDiscoveryTest`, `UrlNormalizerTest`, `SitemapParserTest`, `RobotsTxtTest` |
| Page correlation | Shared-cause failures (network, time-out, DNS, TLS, blocked, 5xx) grouped per website host with per-page impacts; page-specific failures (404, content, header, redirect) stay per page; group resolves after the last page recovers | `PageMonitoringTest` |
| Change monitoring | Text-only fingerprints, selector and exclusions, dynamic values masked, line diff, significance ratio, acknowledgement, opt-in notifications (`page.changed` webhook); header drift for explicitly listed headers | `ContentFingerprintTest`, `PageMonitoringTest` |
| Website overview | Page states incl. "not checked recently", avg/p50/p95/p99 (≥ 20 samples), 14-day availability, active incidents, recent content/DNS/SSL changes, drill-down; list with search, sort, filters, pagination and bulk actions | `WebsitePagesUiTest` |
| Servers | Agent 0.2.0 service checks, disk I/O, network errors, process count, checksum; service incidents; diagnosis separating service failure, unhealthy agent and unreachable host; data coverage, missing metrics, outdated/unknown agent builds; extended history charts | `ServerHealthTest`, agent tests |
| Predictive | Robust z-score anomalies (CPU, memory, swap, load, network, disk growth, website response time and error rate) with minimum absolute change; linear capacity forecasts (disk, memory) with R², ±2 SE band, horizon, confidence; minimum data rules; per-server opt-out and sensitivity; forecast incidents only within 7 days with ≥ medium confidence | `PredictiveMonitoringTest` |
| Security/config | Certificate history, header drift, agent integrity indicator, audit log entries for all new admin actions, admin-only configuration endpoints | several |
| Reports | Per-resource targets, page availability with p95, MTTD, incident duration, recurring problems, monitoring coverage, incidents CSV, printable report, documented method (REPORTS.md) | `ReportsAndOperationsUpgradeTest` |
| API | Pages, page checks, discovery, dependencies, services, predictions, incident assignment, incident correlation fields; Idempotency-Key support | `MonitoringApiTest` |
| Operations | System status page and sidebar indicator (scheduler heartbeat, queue backlog, failed jobs, overdue checks, delivery and discovery failures); retention for all new history tables | `ReportsAndOperationsUpgradeTest` |

### Partially implemented

- **PDF reports**: printable HTML page ("Save as PDF" in the browser); no
  server-side PDF (no new Composer dependencies, see AUDIT.md §3).
- **Escalation policies**: per-severity delays plus extra recipients
  (existing mechanism, now with High); no multi-step on-call schedules.
- **Per-resource notification preferences**: on/off per server, domain,
  page and service; not per user per resource.
- **Vulnerability information**: not integrated (no source configured), so
  nothing is claimed about server security.
- **Content selectors**: simple selectors only (tag, #id, .class,
  tag.class, [attr=value]); no combinators.

### Not implemented (remaining)

1. **English interface**: the UI remains Dutch-only like the existing
   application; translating all ~60 views needs a separate i18n pass.
2. Historical maintenance windows (reports cannot exclude past maintenance).
3. Agent-side buffering of samples during outages.
4. Teams / multi-tenant isolation (single-tenant by design, see AUDIT.md).
5. Scheduled e-mail delivery of reports.

### Known limitations

- Correlation needs confirmed dependencies; without them, simultaneous
  outages on different resources stay separate incidents (by design).
- A grouped page incident created before its peers failed is adopted by the
  website incident through the structural page → website relation; its
  first notification was already sent.
- Forecast uncertainty treats hourly means as independent and is therefore
  indicative only (stated in every forecast).
- Percentiles and the "not checked recently" list are computed in PHP and
  are bounded (50 000 checks, 1 000 pages per website).
- Discovery honours robots.txt for crawled and sitemap URLs; manually added
  pages are always checked.
