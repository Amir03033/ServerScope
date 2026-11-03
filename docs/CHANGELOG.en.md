# What's new in ServerScope

## 3 November 2026 — Quick look

### New

- **Side panel**: click a server, website or incident and a panel slides in
  from the right with status, key numbers, a small chart, services and open
  incidents. Your list stays where it was. Shift-, Cmd- or Ctrl-click opens
  the page as always.
- **Hover cards**: rest the mouse on a server, website or incident for a
  mini card with status, numbers and a small chart.
- **Heartbeat** now sits right below mission control, above the details.

## 3 November 2026 — Mission control

### New

- **Mission control** at the top of the dashboard: one health score (0–100)
  with a sentence that says what is going on, live tiles for every server and
  website, and a timeline of the last 24 hours with incidents, deployments
  and DNS changes. Every problem that costs points is listed, with a link.
  The dashboard as you knew it follows as "Details".
- **Focus mode**: only what needs attention, or "Nothing to do" (remembered
  per browser).

## 3 November 2026 — Time travel, wall screen and live logo

### New

- **Time travel** (NEXUS › Time travel): the last 24 hours or 7 days as a
  film over the skyline. Towers grow and shrink with the CPU, incidents
  flash by. The **director's cut** edits itself: quiet hours race by, every
  incident slows the film down with the camera on its tower and a caption,
  with an opening title and a summary at the end. Scrub with the slider,
  play freely at 1–8× and **export a video** (MP4 or WebM, recorded in your
  own browser).
- **Wall screen** (NEXUS › Wall screen): a TV mode that directs itself.
  Calmly through skyline, globe, heartbeat and a summary; on a new incident
  a large alert and the camera on its cause at once. Full screen, keeps the
  screen awake, and optionally a **screensaver** after 5–30 idle minutes.
- **Live logo and tab icon**: the logo and the tab icon beat in your
  infrastructure's rhythm and turn amber or red on problems; the tab shows
  the number of active incidents, also in the background.

### Improved

- **Menu**: NEXUS folds; Skyline, Globe, Time travel and Wall screen show on a
  NEXUS page or when you open it (remembered per browser).
- **Wall screen**: during an incident every scene is shown (also Globe and
  Summary), not only the tower and the heartbeat.

- The heartbeat on phones: name, line and numbers stacked and readable.

## 3 November 2026 — Heartbeat, skyline and status badges

### New

- **Heartbeat on the dashboard**: every server gets a live ECG line. The
  rate follows its CPU, the width of the beat its slowest website. When
  something fails the line goes haywire; an offline server has no signal.
  Every row also says the rhythm and the numbers in words.
- **Skyline** (NEXUS › Skyline): your servers as a city at night. Busier
  servers stand taller, lit windows are running services, roof lights are
  the websites. When something fails the lights flicker; when a server
  stops reporting they go out.
- **Status badges**: live, animated SVG badges of a public status page
  (overall or per component) for your README or website, with ready-made
  Markdown on the edit page. Only the public name and state; light or
  dark, English or Dutch.

### Improved

- The green "Reachable" chips (globe, billing) are green again.

## 3 November 2026 — A better-looking globe

### Improved

- **Globe**: a new look — a dark ocean with a glowing rim and atmosphere,
  the continents as fine round dots, glowing pins with a beam of light and
  card-style labels. Measuring locations on (almost) the same spot share
  one pin and one label.
- **Stand-by**: a working probe that had nothing to confirm shows
  "Stand-by" instead of "Not measured"; "Not measured" is kept for a probe
  that does not respond.
- **Own menu item**: the globe is now in the menu, right below NEXUS.

## 2 November 2026 — Flows, status page hero and globe

### New

- **Flows in the 3D map**: light flows along the connections from what
  serves to what uses it — busier servers send more, quicker websites flow
  faster — and piles up where something fails. The flows picture measured
  load (CPU, response time), not measured traffic.
- **3D picture on your status page** (optional, per status page): your
  components around a core in the overall status, with flowing light while
  they work and a visible jam during an outage. Only what the page already
  shows is used.
- **Globe** (NEXUS › Globe): where ServerScope measures from, and per
  website whether it was reachable from each location.

## 2 November 2026 — NEXUS in 3D

### New

- The NEXUS map has a **3D** view: servers stand as towers, services ring
  them, websites orbit the server they run on and pages float above their
  website. Drag to rotate, scroll to zoom, click for details.
- Active incidents are visible as a pulse at the failing resource and as
  waves running along the connections to everything that depends on it.
- Filters, search, impact view and the side panel work as on the 2D map;
  your choice of view is remembered.

### Fixed

- The NEXUS side panel no longer shows the word "null" for empty parts.

## 2 November 2026 — Root cause analysis: rules and AI together

### Improved

- The incident page now has one **Root cause analysis** section: what was
  observed, the most likely explanation by fixed rules, the AI's
  interpretation of that result (Pro and Premium), other explanations, what
  remains uncertain and what to check next.
- The AI now interprets the investigation itself. It refers to the same
  evidence (E1, E2, …) and candidates, can answer "cause unknown", and its
  confidence is shown as a judgement, never as a probability. References to
  evidence that does not exist are removed and the number is shown.
- No extra AI usage: still at most one automatic analysis per new incident,
  with the same plans, limits and cooldowns.

## 2 November 2026 — NEXUS Intelligence: investigations without AI

### New

**Investigations of incidents (every plan, no AI)**
- ServerScope can now investigate an incident: it collects the evidence
  around it (incidents, checks, service changes, DNS changes, deployments,
  configuration changes, agent updates, metrics, maintenance) and applies
  fixed rules to suggest candidate causes.
- Each candidate shows its confidence (high, medium, low or insufficient),
  the evidence that supports it, the evidence that contradicts it and what
  information is missing. "Cause not determined" is a valid answer:
  ServerScope does not guess.
- Possible impact: what depends on the suspected resource, through which
  dependency, and whether it was failing, healthy or not observed.
- Read-only next steps to check. ServerScope changes nothing itself.
- Investigations start automatically when an incident opens, reopens or is
  resolved, and administrators can run one again. Every run is kept
  unchanged, so you can compare runs.
- Find it on the incident page, from Time Machine and from the incident
  block on the NEXUS map ("Show on NEXUS map" highlights the impact).
- The AI analysis (Pro and Premium) is unchanged and stays separate.

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
