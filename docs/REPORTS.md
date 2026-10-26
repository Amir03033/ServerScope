# Availability and incident reporting — calculation method

All figures are calculated from ServerScope's own monitoring data. They are
not a contractual SLA determination.

## Domains (main URL)

- **Uptime** = successful HTTP checks / performed HTTP checks in the period.
- **Coverage** = performed checks / expected checks (period ÷ interval).
  Periods without checks (monitoring paused, scheduler down) lower the
  coverage; they are **never** counted as available or unavailable.
- **Observed downtime** = failed checks × check interval.

## Website pages

Same method as domains, from each page's own checks (`page_checks`). A
*degraded* check (slower than the page's response-time threshold) counts as
available; a failed status, content, header, redirect, TLS or network check
counts as unavailable. p95 response time is only shown with at least 20
successful checks.

## Servers

- **Downtime** = time covered by agent-offline incidents within the period.
- **Coverage** = received metric samples / expected samples (one per minute).
- A server without any samples in the period has no uptime figure.

## Maintenance

- Domain and page checks keep running during a maintenance window and
  **count normally** in uptime: nothing is excluded silently. Incidents and
  notifications are suppressed during maintenance.
- For servers no agent-offline incident is opened during maintenance, so
  that time does not count as downtime. This is the only maintenance effect
  on reports, and it is stated on the report page.
- Maintenance windows are not stored historically (only the current or next
  window), so reports cannot exclude past maintenance after the fact.

## Targets

A resource's own availability target (`sla_target` on a page, otherwise its
website, server or domain) takes precedence over the target entered on the
report page (default `SERVERSCOPE_REPORT_TARGET`, 99.9%).

## Incident metrics

- **MTTD** (time to detect) = mean of `opened_at − started_at`, where
  `started_at` is the first failed check (domains, pages), the start of the
  threshold period (resource alerts) or the last heartbeat (agent offline).
  Manual incidents and incidents created before this feature have no start
  and are excluded.
- **MTTA** = mean time from opening to the first acknowledgement or start
  of investigation.
- **MTTR** = mean time from opening to resolution (the last resolution for
  reopened incidents, so reopened time is included).
- Incidents without the relevant timestamp are excluded, never counted as 0.
- **Total incident duration** counts root incidents only (grouped incidents
  are part of their root's time) and only the part inside the period.
- **Recurring problems** = fingerprints with more than one incident or
  reopening in the period.

## Exports

- Availability CSV: one row per domain, server and page with uptime,
  coverage, downtime, response time, incidents and target.
- Incidents CSV: one row per incident opened in the period with detection,
  acknowledgement and resolution durations and the root incident.
- **Afdrukken / PDF** opens a printable page; use the browser's "Save as
  PDF". There is no server-side PDF generation.
Values that start with `=`, `+`, `-`, `@` are prefixed with `'` to prevent
formula injection in spreadsheets.
