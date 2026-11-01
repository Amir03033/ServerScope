# ServerScope REST API (v1)

Base URL: `https://serverscope.nl/api/v1`

## Authentication

Create a personal token under **Profiel → API-tokens**. Send it as a bearer
token:

```
Authorization: Bearer ssk_…
Accept: application/json
```

- Tokens act with the permissions of their owner, limited to their
  abilities: `read` (always) and optionally `write`.
- Only a SHA-256 hash is stored; the token is shown once.
- Tokens can expire (30/90/365 days) and be revoked at any time.
- Rate limit: 120 requests per minute per token (`SERVERSCOPE_API_RATE_LIMIT`),
  answered with HTTP 429 when exceeded.

## Conventions

- JSON only. Lists are paginated: `?page=2&per_page=50` (max 100) and return
  `data`, `links` and `meta`.
- Timestamps are ISO 8601 in UTC.
- Errors: `{"message": "...", "errors": {"field": ["..."]}}` with status
  401 (token missing/invalid), 403 (ability missing), 404, 409 (invalid
  incident transition), 422 (validation) or 429.

## Endpoints

| Method | Path | Ability | Notes |
|---|---|---|---|
| GET | `/servers` | read | `status` = online/offline/pending/maintenance, `environment` |
| GET | `/servers/{id}` | read | includes latest metrics |
| GET | `/servers/{id}/metrics` | read | `from`, `to` (ISO 8601), `limit` ≤ 1440 (default last hour, 120 samples) |
| GET | `/domains` | read | `status` = healthy/warning/critical/unknown |
| GET | `/domains/{id}` | read | HTTP, SSL, DNS and registration state; `registration.status` is `verified` only when the registry publishes a date |
| GET | `/domains/{id}/checks` | read | `type` = http/ssl/dns/expiry |
| GET | `/incidents` | read | `state` = active (default)/resolved/closed/all, `severity` |
| GET | `/incidents/{id}` | read | includes the event timeline |
| POST | `/incidents/{id}/acknowledge` | write | |
| POST | `/incidents/{id}/investigate` | write | |
| POST | `/incidents/{id}/resolve` | write | optional `notes` |
| POST | `/incidents/{id}/comments` | write | `message` |
| POST | `/incidents/{id}/assign` | write | `user_id` (or null to unassign) |
| GET | `/domains/{id}/pages` | read | monitored pages; `status` = up/degraded/down/unknown/paused/pending/rejected |
| POST | `/domains/{id}/pages` | write + admin | `urls` (newline separated, full URLs or paths) plus optional page settings (see below); returns `meta.added`/`duplicates` |
| GET | `/pages/{id}` | read | page state and settings |
| PUT | `/pages/{id}` | write + admin | `url` plus settings (omitted settings fall back to defaults) |
| DELETE | `/pages/{id}` | write + admin | deletes the page and its check history |
| POST | `/pages/{id}/pause` · `/resume` · `/approve` · `/reject` | write + admin | approve re-validates the destination (SSRF rules) |
| GET | `/pages/{id}/checks` | read | `result` = up/degraded/down; includes `failure_type` and redirect chain |
| POST | `/domains/{id}/discovery` | write + admin | starts a background discovery run (202); 409 when one is running or the hourly limit is reached |
| GET | `/domains/{id}/discovery-runs` | read | results, sources and errors of discovery runs |
| GET | `/dependencies` | read | confirmed dependencies |
| POST | `/dependencies` | write + admin | `dependent_type`, `dependent_id`, `dependency_type`, `dependency_id` (types: server, domain, page, service), `label`; cycles are refused |
| DELETE | `/dependencies/{id}` | write + admin | |
| GET | `/servers/{id}/services` | read | reported service states; `status` is `stale` while the agent is offline |
| GET | `/predictions` | read | `kind` = anomaly/forecast, `status` = active (default)/resolved; includes window, trend, horizon, confidence and reason |

Incident objects additionally contain `started_at`, `time_to_detect_seconds`,
`parent_id` and `correlation` (`reason_code` = dependency/same_resource, a
human `reason` and the resource `path`); the detail endpoint also returns
`children` and `impacts` (affected resources, e.g. the pages of a grouped
website incident). Severity values: `info` (low), `warning` (medium),
`high`, `critical`.

### Page settings

`check_interval_minutes` (default 5), `timeout_seconds` (10),
`failure_threshold` (2), `recovery_threshold` (2), `expected_status_codes`
(e.g. `200-299,301`), `max_response_ms`, `follow_redirects` (true),
`expected_final_url`, `keyword_present`, `keyword_absent`,
`header_assertions` (array of `{name, mode: present|absent|equals|contains, value}`),
`monitored_headers` (array of header names tracked for drift),
`content_monitoring`, `content_selector`, `content_exclusions` (array),
`alerts_enabled` (true), `alert_severity` (`high`), `notify_content_changes`,
`sla_target`.

### Permissions

Every token can read. Writes need the `write` ability; configuration
changes (pages, discovery, dependencies) additionally need a token owned by
an administrator (otherwise 403). Incident actions follow the web
interface: any user may acknowledge, investigate, resolve, comment and
assign.

### Idempotency

Write requests may send `Idempotency-Key: <8-128 characters>`. Repeating a
request with the same key (per token, within 24 hours) returns the stored
response with `Idempotent-Replayed: true` instead of executing it again;
the same key with a different body or path is refused with 422, a
concurrent duplicate with 409.

## Examples

Add pages and start discovery:

```sh
curl -s -X POST https://serverscope.nl/api/v1/domains/3/pages \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" -H "Accept: application/json" \
  -H "Idempotency-Key: deploy-2026-10-28-pages" \
  -d urls=$'/\n/pricing\n/api/health' -d keyword_absent="Fatal error" -d alert_severity=critical

curl -s -X POST https://serverscope.nl/api/v1/domains/3/discovery \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" -H "Accept: application/json"
```

Declare that a website runs on a server (enables incident grouping):

```sh
curl -s -X POST https://serverscope.nl/api/v1/dependencies \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" -H "Accept: application/json" \
  -d dependent_type=domain -d dependent_id=3 -d dependency_type=server -d dependency_id=1 -d label="draait op"
```

List active incidents:

```sh
curl -s https://serverscope.nl/api/v1/incidents?state=active \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" -H "Accept: application/json"
```

The monitoring agent uses a separate endpoint (`POST /api/agent/metrics`) with
per-server agent tokens; those tokens do not work for this API.

## Deployments (Deploy Judge)

| Method | Path | Ability | Notes |
|---|---|---|---|
| `POST` | `/api/v1/deployments` | write | record a deployment; supports `Idempotency-Key` |
| `GET` | `/api/v1/deployments` | read | newest first; filters `application`, `environment`, `per_page` (1–100) |
| `GET` | `/api/v1/deployments/{id}` | read | the deployment plus `analysis` (before/after comparison) |

Request body for `POST`:

| Field | Required | Rules |
|---|---|---|
| `application` | yes | string, max 100 |
| `environment` | no | `[A-Za-z0-9._-]`, max 50 (default `production`) |
| `version` | no | string, max 100 |
| `commit_sha` | no | 7–64 hex characters (stored lower case) |
| `deployed_at` | no | ISO 8601; within the last 30 days and at most 5 minutes ahead (default: now) |
| `source`, `actor` | no | strings, max 50 / 100 (e.g. `github-actions`, the user) |
| `url` | no | http(s) URL of the run or release |
| `external_id` | no | `[A-Za-z0-9._:/-]`, max 191; a second request with the same value returns the existing deployment (`200`) instead of creating one |
| `resources` | yes | 1–50 items `{ "type": "server" \| "domain" \| "page" \| "service", "id": <int> }`; every resource must belong to the token's team (otherwise `422`, also for other teams' ids) |

Responses: `201` created, `200` existing (same `external_id`), `401`
invalid token, `403` token without write ability, `422` validation.

**Analysis.** `analysis.status` is `pending` until 60 minutes after the
deployment, then `regression`, `no_regression` or `insufficient_data`
(`no_resources` when no linked resource exists any more). `analysis.checks`
compares the 60 minutes before and after per linked resource:
availability, HTTP 5xx share and median response time from the website's
or page's own checks (at least 10 checks on each side), and CPU and
memory medians from raw samples (at least 20 on each side; when raw
samples are no longer kept, hourly averages over 3 hours from the
rollups). A change only counts as `regression` when it exceeds a fixed
minimum (availability −5 points, 5xx +5 points, response time +30 % and
+100 ms, CPU +15 points, memory +10 points) and, for samples, the normal
spread before the deployment. `new_incidents` lists incidents opened on a
linked resource within 60 minutes after the deployment, when none opened
in the hour before. Nothing is notified. **A change after a deployment is
not proof that the deployment caused it**; `overlapping_deployments` lists
other deployments in the same period.

```sh
curl -sS -X POST https://serverscope.nl/api/v1/deployments \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Idempotency-Key: deploy-shop-1f3a9c2" \
  -d '{"application":"shop","environment":"production","version":"1.4.2","commit_sha":"1f3a9c2","resources":[{"type":"server","id":3},{"type":"domain","id":7}]}'
```

### GitHub Actions example

Store an API token with the write ability as the secret
`SERVERSCOPE_API_TOKEN`, and add this step after the deploy step:

```yaml
      - name: Record deployment in ServerScope
        if: success()
        env:
          SERVERSCOPE_API_TOKEN: ${{ secrets.SERVERSCOPE_API_TOKEN }}
        run: |
          curl -sS --fail-with-body -X POST https://serverscope.nl/api/v1/deployments \
            -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" \
            -H "Content-Type: application/json" -H "Accept: application/json" \
            -H "Idempotency-Key: gh-${{ github.run_id }}-${{ github.run_attempt }}" \
            -d "{\"application\":\"${{ github.event.repository.name }}\",\"environment\":\"production\",\"version\":\"${{ github.ref_name }}\",\"commit_sha\":\"${{ github.sha }}\",\"source\":\"github-actions\",\"actor\":\"${{ github.actor }}\",\"url\":\"${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}\",\"external_id\":\"gh-${{ github.run_id }}\",\"resources\":[{\"type\":\"server\",\"id\":3}]}"
```

Deployments appear under **Deployments**, as markers on the domain
response-time chart and the Time Machine charts, on the server page, and in
the Time Machine timeline of incidents on the linked resources.

## Webhooks

Administrators configure endpoints under **Beheer → Webhooks**. Events:
`incident.opened`, `incident.reopened`, `incident.resolved`,
`incident.escalated` (threshold crossed, severity increased or not
acknowledged in time), `dns.changed` and `page.changed` (significant
content change of a page that opted in), plus `test`. Incident payloads
include `parent_id`/`correlation`; generic endpoints receive grouped
(child) incidents too, Discord channels follow the same suppression as
e-mail (one message per underlying problem).

Each delivery is a `POST` with a JSON body:

```json
{"id": "<delivery uuid>", "event": "incident.opened", "created_at": "2026-10-26T10:00:00+00:00", "data": {"incident": {"id": 12, "title": "…", "severity": "critical", "status": "open", "…": "…"}}}
```

Headers: `X-ServerScope-Event`, `X-ServerScope-Delivery` (use it to ignore
duplicates), `X-ServerScope-Timestamp` (Unix time) and
`X-ServerScope-Signature: sha256=<hex>` where the hex value is
`HMAC-SHA256(secret, timestamp + "." + raw_body)`.

Verify in PHP:

```php
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
abs(time() - (int) $timestamp) < 300 && hash_equals($expected, $signatureHeader);
```

Delivery rules: only public https destinations on allowed ports, checked
when saving and again before every attempt (DNS rebinding safe); redirects
are not followed; any 2xx is success; network errors, timeouts, 408, 429 and
5xx are retried up to 6 times (1 min, 5 min, 15 min, 1 h, 6 h); other
responses fail immediately. After 20 consecutive failed deliveries the
endpoint is disabled. With `QUEUE_CONNECTION=sync` there are no retries.

### Discord

Administrators can also add a **Discord** endpoint (**Beheer → Webhooks →
Discord koppelen**). It uses the same events, delivery log, retries and
auto-disable rules, but posts a Discord embed instead of the signed JSON
body:

- Only `https://discord.com/api/webhooks/{id}/{token}` URLs are accepted
  (also `discordapp.com`, `ptb.`/`canary.` hosts and `/api/v10/`); no query
  string.
- The full URL contains the token and is stored encrypted (`APP_KEY`). Pages,
  audit logs and delivery errors only show
  `https://discord.com/api/webhooks/{id}/••••••`. Replace the URL via
  **Bewerken**; an empty field keeps the stored one.
- Embeds show the event (server offline/online, alert, website, SSL,
  certificate expiry, DNS, domain registration), the affected server or
  domain, status, severity, timestamps and a link to the incident.
  Mentions are disabled.
- An optional minimum severity skips lower incidents. The same incident
  event in the same state is posted at most once per
  `SERVERSCOPE_NOTIFY_CHAT_COOLDOWN` minutes (default 15), which absorbs
  flapping; generic endpoints still receive every event.
- **Testmelding naar Discord** sends one test embed (manual only; nothing is
  sent on deploy).
