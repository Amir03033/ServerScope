# What's new in ServerScope

## 26 October 2026 — Website monitoring, smarter incidents and forecasts

This update turns ServerScope into more than an uptime monitor. You can now
monitor complete websites page by page, related outages are combined into
one incident, and ServerScope warns you in advance when a disk is about to
fill up. All existing monitoring, history and integrations keep working as
before.

### New

**Per-page monitoring**
- Monitor more than the homepage: add pages such as `/pricing`, `/login`
  or `/api/health`. Each page has its own interval, thresholds,
  notifications and history.
- Per page you can check the status code, maximum response time, the final
  address after redirects, text that must or must not appear, and response
  headers (for example `Strict-Transport-Security`).
- ServerScope tells apart a network error, a time-out, a DNS error, a
  certificate error, a redirect error, a wrong status code and a failed
  content check.
- A slow page is marked "degraded"; that does not count as an outage.
- Pages can be paused without losing their history.

**Automatic page discovery**
- ServerScope finds pages through robots.txt, XML sitemaps (including
  sitemap indexes and compressed sitemaps) and internal links.
- Safe by default: at most 50 pages, only your own domain, and discovered
  pages wait for your approval. Broader settings must be confirmed
  explicitly.
- Approve or reject discovered pages at once with bulk actions.

**Website overview**
- For each website you see how many pages are healthy, degraded or down,
  and which pages were not checked recently.
- It also shows the average response time and p50/p95/p99 values, daily
  availability, active incidents and recent content, DNS and certificate
  changes.
- Search, sort, filter and bulk actions in the page list.

**Content change tracking**
- ServerScope notices when the text of a page changes and shows what was
  added and removed.
- Dates, times, counters and session codes are ignored by default. You can
  also pick one part of the page or exclude dynamic blocks.
- A change is never an outage by itself. Acknowledge expected changes with
  one click, and turn on notifications per page if you want them.
- You can also track selected response headers, so you see when a security
  header changes, for example.

**Smarter incidents**
- Record which website runs on which server and which services it depends
  on (for example server → Nginx → website → MySQL). When a server fails,
  you get one incident with the affected websites underneath, instead of a
  stream of separate alerts.
- When several pages fail for the same reason, that becomes one website
  incident with details per page. A single page returning a 404 stays its
  own incident.
- Every grouped incident explains why it was grouped. Outages that merely
  happen at the same time are never combined.
- ServerScope suggests relations itself (for example when a domain points
  to a server's IP address). They are only used once you confirm them.

**Services on your servers**
- The agent can now monitor Nginx, Apache, MySQL, PostgreSQL, Redis,
  PHP-FPM and other systemd services. You choose which ones.
- ServerScope clearly separates a stopped service, a monitoring agent that
  does not respond and a server that is really unreachable. For the last
  two it checks whether websites on that server still respond.

**Forecasts and anomalies**
- ServerScope compares CPU, memory, swap, load, network, disk growth,
  response time and error rate with the resource's own history of the past
  week. Only clear, large deviations are shown.
- A capacity forecast shows when a disk or memory will fill up if the
  current trend continues, with a range and a confidence level.
- Every forecast states which period was used, what the trend is and why
  it was raised. Nothing is forecast from too little data.
- Predictive alerts can be switched off per server, and the sensitivity can
  be set per server.

**System status**
- A new page shows whether ServerScope itself is working properly: is the
  scheduler running, is the queue keeping up, do checks run on time and are
  notifications delivered.

### Improved

- **Four severity levels:** Low, Medium, High and Critical. "High" is new,
  for alert rules, incidents, notification preferences and webhooks.
- **Recovery confirmation:** an alert rule can wait for a configurable
  number of good samples in a row before it reports recovery. This avoids
  recovery alerts during a brief dip.
- **Time to detect (MTTD):** besides MTTA and MTTR you now see how quickly a
  problem was reported as an incident after the first failed check.
- **Recurrence:** an incident shows how often the same problem occurred
  before.
- **Notifications per resource:** switch notifications off for a server,
  domain, page or service. Incidents are still recorded.
- **Certificate history:** every certificate seen for a domain, with issuer,
  validity and when it was in use.
- **Reports:**
  - availability targets per server, domain and page;
  - availability per page;
  - MTTD and the total duration of incidents;
  - recurring problems;
  - an overview of how much of your environment was actually monitored;
  - an export of all incidents;
  - a printable version that you can also save as a PDF.
- **Server page:** charts for swap, network traffic, disk activity and
  network errors.
- **Data retention:** the new data, such as page checks and forecasts, can
  also be cleaned up after a configured period.

### Agent 0.2.0

- New: service monitoring via `SERVERSCOPE_SERVICES`, disk activity, network
  errors, process count and a checksum of the agent itself.
- The agent only reads data and never runs remote commands.
- Agent 0.1.0 keeps working. Upgrading is needed for the new server data.

### API

- New endpoints for pages, page checks, discovery, dependencies, services,
  forecasts and assigning incidents.
- Incidents now also include the time to detect, the root incident and the
  reason for grouping.
- Write requests support `Idempotency-Key`, so a repeated request is not
  executed twice.
- Existing endpoints and fields are unchanged.

### Security

- Every check and every discovery run uses the same protection as the
  existing domain monitoring. Internal, local and cloud-metadata addresses
  are blocked, also after a redirect, and a DNS answer cannot be switched to
  an internal address afterwards.
- Sitemaps with hidden references to files are refused.
- New administrative actions are recorded in the audit log.

### Changed behaviour

- When you resolve an incident by hand, you now describe the cause and the
  fix in a few words.
- An incident grouped under another incident does not send its own alert
  while the root incident is active. Generic webhooks still receive every
  incident, with a reference to the root incident.

### Good to know

- Incidents of different servers and websites are only grouped when you
  have recorded their relation under **Dependencies**.
- The interface is available in Dutch only for now.
- "No problems found" means the configured checks report nothing. It is not
  a guarantee that a server or website is secure.
