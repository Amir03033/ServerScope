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

## Example

```sh
curl -s https://serverscope.nl/api/v1/incidents?state=active \
  -H "Authorization: Bearer $SERVERSCOPE_API_TOKEN" -H "Accept: application/json"
```

The monitoring agent uses a separate endpoint (`POST /api/agent/metrics`) with
per-server agent tokens; those tokens do not work for this API.

## Webhooks

Administrators configure endpoints under **Beheer → Webhooks**. Events:
`incident.opened`, `incident.reopened`, `incident.resolved`,
`incident.escalated` (threshold crossed, severity increased or not
acknowledged in time) and `dns.changed`, plus `test`.

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
