# NEXUS Intelligence — deterministic investigations (Phase 1)

NEXUS Intelligence investigates an incident from data ServerScope already
stores. It collects the evidence around the incident, applies fixed rules to
propose candidate causes with a stated confidence, lists what may be
affected through recorded dependencies, and suggests read-only next steps.

- **No AI, no external service, no API key.** Available on every plan; a run
  costs nothing. The AI Incident Investigator (`incident_analyses`,
  Pro/Premium) is separate and unchanged; its panel stays on the incident
  page next to the investigation panel.
- **Hypotheses, not conclusions.** Every candidate cause carries supporting,
  contradicting and missing evidence and the rules that set its confidence.
  "Cause not determined" (`insufficient_evidence`) is a valid outcome and is
  the leading one whenever no candidate reaches medium confidence.
- **Read-only.** Next steps are suggestions for a person. Nothing is changed,
  restarted or executed.

## Where it appears

| Place | What |
|---|---|
| Incident page | Compact panel: leading explanation, confidence, limitations, link, (admin) run again |
| `/incidents/{id}/investigation` | The incident's latest investigation, or its empty state |
| `/investigations/{id}` | One stored run: overview, leading explanation, all candidates and ruled-out ones, timeline, impact, evidence explorer (filter by resource, kind, phase), next steps, limitations, history |
| `/investigations/{id}/export` | The stored snapshot as JSON (codes, for review and traceability) |
| Time Machine | Link to the investigation |
| NEXUS map | Incident block in the side panel links to the investigation; "Show on NEXUS map" opens `/nexus?focus=type:id`, which selects the suspected resource and shows its impact |

Every team member may view investigations (like incidents and Time
Machine). Team administrators may request a new run (like AI analysis); that
request is audited (`incident.investigation_requested`).

## How a run works

```
IncidentOpened / IncidentReopened / IncidentResolved   ── QueueInvestigation (root incident, after a delay)
Admin: "Investigate again"                             ── InvestigationController@store (audited)
            │
            ▼
investigations row (queued)  ──►  RunInvestigation job  ──►  InvestigationRunner
  1. ContextBuilder: window + scope (ScopeResolver), shared collectors, metric coverage,
     check summaries, maintenance, grouping, checks of other websites on a silent server
  2. HypothesisEngine: rule catalog → ConfidenceScorer → ranking (+ insufficient_evidence)
  3. ImpactAnalyzer: dependents of the leading cause, with paths and what was observed
  4. NextStepAdvisor: read-only steps per hypothesis
  5. TeamIntegrityGuard: every referenced resource and row belongs to the team
  6. Store the immutable result (completed / partial); earlier runs become superseded
```

### Shared evidence collection (Time Machine and Intelligence)

Time Machine's collection was moved into `app/Services/Incidents/Evidence`
without changing `TimeMachine::build()`'s output (frozen by
`tests/Feature/TimeMachineContractTest.php` in both languages):

- `ScopeResolver`: the window (6 h before the onset to 2 h after recovery,
  at most 48 h) and the resources (the incident's resource, up to 3 levels
  of confirmed/structural dependencies of the same team, and the services on
  those servers).
- Collectors (`Collectors::ORDER`): incidents, services, domain checks, DNS
  changes, page checks, predictions (stored, never re-run), agent updates,
  deployments, audit log. Each emits `EvidenceRecord`s: a stable code,
  parameters, timestamp, resource key and the source row (`{type, id}`).
- `MetricEvidence`: hourly history (`MetricHistory`), baseline shifts, and
  for Intelligence the sample coverage, longest gap, uptime resets and
  future-dated samples.

Time Machine renders the records as sentences (`TimeMachine::title()`); the
investigation stores the codes and renders them in the reader's language.
`IncidentEvidence` (AI input) still reads Time Machine's output and is
unchanged.

### Rules and confidence

Levels are ordinal: `high`, `medium`, `low`, `insufficient`. No percentages:
there is no calibration data that would justify them.

| Rule | Hypothesis codes | Strongest level | Notes |
|---|---|---|---|
| DirectSignalRule | `certificate_problem`, `registration_expiry`, `dns_resolution_failure` | high | The incident's own check names the problem; what caused it is listed as missing |
| MaintenanceRule | `planned_maintenance` | medium | Maintenance window on the resource or a dependency covering the onset (only the current/last stored window is known) |
| UpstreamIncidentRule | `upstream_incident`, `upstream_signal` | high / low | Another incident on the resource or a dependency that started first, and whose kind of failure can cause this one (`CorrelationEngine::CAUSES`); otherwise only a low signal |
| AgentOrHostRule | `agent_unhealthy`, `host_unreachable`, `agent_offline_mixed`, `agent_offline_unknown` | medium | Same decision as `ServerDiagnosis` (`classifyOffline`), applied to the checks of other websites on the server during the incident; without them: insufficient |
| ServiceFailureRule | `service_failed` | high / medium | High only with a confirmed dependency on the service itself; a service merely running on a server the resource depends on is structural exposure (medium) |
| ApplicationResponseRule | `application_response` | medium | Wrong status/content/header/redirect: the server answered |
| RebootRule | `reboot` | medium | Uptime reset 30 min before to 10 min after the onset |
| ResourcePressureRule | `disk_exhaustion`, `memory_exhaustion`, `swap_pressure`, `cpu_saturation` | medium | Never high (correlation); medium needs a level above the threshold or an earlier stored anomaly/forecast |
| DnsChangeRule | `dns_change` | medium (DNS failure) / low | Timing |
| RecentChangeRule | `recent_deployment`, `recent_config_change`, `recent_agent_update` | low (medium for an agent update before the agent went silent) | Timing only |

Scoring (`ConfidenceScorer`), applied to the rule's level:

1. No supporting evidence → insufficient.
2. Contradicting evidence → one level lower (`contradicted`).
3. A source the rule depends on failed → at most medium (`collector_failed`).
4. A source or the evidence list hit its limit → at most medium (`truncated`).
5. The server's metric history is missing → at most medium (`data_gap`).

A candidate that started after the onset, or had recovered before it, is
**rejected** and listed under "Ruled out" with the reason. Inferred
DNS-to-IP relations are shown as unconfirmed and never used as evidence.

### Impact

`ImpactAnalyzer` walks `DependencyGraph::descendants()` (team-checked) from
the leading cause (or the incident's resource), up to
`impact_depth` levels and `max_resources` resources, and records per
dependent: the path, the kind of each edge (structural/confirmed), and what
was observed in the incident period — **failing** (failed checks or an
incident), **healthy** (only successful checks) or **not observed**
(exposure, not an observed impact). The page also shows the current status
of each resource, labelled as "now".

## Missing and incomplete evidence

- No metrics, no raw samples, no dependencies, no server, a failed source,
  future-dated samples: listed as limitations (codes in `result.gaps`).
- A silent agent produces an `agent_silent` coverage item; it is never read
  as "healthy" or as an unreachable host by itself.
- A collector that throws is recorded as `failed`; the run is stored as
  **partial**, confidence is capped where that source mattered, and the
  exception text is only logged (never stored or shown).

## Tenant isolation

- `investigations.team_id` is required; the model uses `BelongsToTeam`.
- The job's payload is only the id; the team is read from the row. All
  collector, impact and guard queries filter on the team explicitly
  (child tables through team-filtered parents); jobs run without a tenant.
- `DependencyGraph` checks every edge's team; `TeamIntegrityGuard` checks
  every resource key and source row of the result before it is stored and
  fails the run (`integrity_violation`) instead of storing foreign ids.
- Routes are tenant-bound and each action also compares the team (404).
- Untrusted text (error messages, service states, audit descriptions,
  incident titles) is shortened, scrubbed of secrets/e-mail/IP addresses
  (`IncidentEvidence::clean`) for details, and rendered escaped. No secret
  or credential column is read.

## Configuration (`config/serverscope.php` → `intelligence`)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `auto_triggers` | `SERVERSCOPE_INTELLIGENCE_TRIGGERS` | `opened,reopened,resolved` | Lifecycle events that queue a run (empty = manual only) |
| `auto_delay_seconds` | `SERVERSCOPE_INTELLIGENCE_DELAY` | 120 | Delay so grouping and the next samples are in |
| `max_runs_per_team_per_hour` | `SERVERSCOPE_INTELLIGENCE_MAX_PER_TEAM_HOUR` | 60 | Cap per team (automatic and manual) |
| `manual_cooldown_seconds` | `SERVERSCOPE_INTELLIGENCE_MANUAL_COOLDOWN` | 60 | Between manual runs of one incident |
| `max_resources` | `SERVERSCOPE_INTELLIGENCE_MAX_RESOURCES` | 150 | Scope and impact bound |
| `max_evidence` | `SERVERSCOPE_INTELLIGENCE_MAX_EVIDENCE` | 300 | Evidence items kept (problems and changes first) |
| `impact_depth` | `SERVERSCOPE_INTELLIGENCE_IMPACT_DEPTH` | 4 | Levels of dependents |
| `job_timeout_seconds` | `SERVERSCOPE_INTELLIGENCE_JOB_TIMEOUT` | 45 | Job timeout; below the ~55 s scheduler worker |

Automatic runs only happen for root incidents with a monitored resource;
while a run of an incident is queued or running, further events reuse it.

## Retention

`serverscope.retention.investigations_days` (`SERVERSCOPE_RETAIN_INVESTIGATIONS_DAYS`,
default **365**; empty or 0 = keep forever). `serverscope:prune` removes runs
older than that, but never a queued/running run and never a run of an
incident that is still active. A snapshot keeps the evidence it was built
from (derived values, resource names, timestamps) after the monitoring rows
themselves have been pruned.

## Queue workers

Runs are queued (`RunInvestigation`, unique per run, 2 tries, 30 s backoff,
timeout `job_timeout_seconds`). The scheduler worker
(`queue:work --stop-when-empty --max-time=55`, `SERVERSCOPE_SCHEDULER_QUEUE_WORKER`)
or a supervised `queue:work` processes them; no extra worker or service is
needed. A run left "running" by a crashed worker is taken over by the next
attempt after twice the timeout; a job that fails for good marks the run
`failed` (`queue_failure`).

## Diagnosing failed or partial runs

| Status / code | Meaning | What to do |
|---|---|---|
| `partial` | One or more sources could not be read (`result.collectors`, `result.gaps`) | Check the log entry "Investigation collector failed" (collector and exception class) |
| `failed` / `internal_error` | Unexpected exception | Log entry "Investigation failed"; run again |
| `failed` / `queue_failure` | The worker stopped or timed out | Check the queue worker; run again |
| `failed` / `integrity_violation` | The result referred outside the team; nothing was stored | Log entry "Investigation integrity violation"; report as a bug |
| `failed` / `incident_missing` | The incident was deleted | — |

## Deployment

1. `php artisan migrate` (adds `investigations`; additive, rollback
   `php artisan migrate:rollback --step=1`).
2. Deploy the rebuilt assets in `public/build` (NEXUS panel link and focus).
3. Optional: set the `SERVERSCOPE_INTELLIGENCE_*` and
   `SERVERSCOPE_RETAIN_INVESTIGATIONS_DAYS` variables.
4. Make sure a queue worker runs (the scheduler worker is enough).

## Tests

```
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Intelligence tests/Feature/TimeMachineContractTest.php tests/Feature/TimeMachineTest.php
```

The full suite needs more than PHP's default 128 MB (`-d memory_limit=1G`).

## Not in this phase

AI explanations of investigations, forecasting beyond the existing
predictions, simulations ("digital twin"), autonomous remediation, user
feedback on hypotheses, and the history of earlier maintenance windows (only
the current/last one is stored).
