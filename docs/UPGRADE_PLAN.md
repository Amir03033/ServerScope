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
