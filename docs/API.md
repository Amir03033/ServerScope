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
