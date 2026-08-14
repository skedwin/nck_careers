# API overview

Base URL: `/api/v1`

## Response envelope

Success:

```json
{
  "success": true,
  "data": {},
  "message": "Optional message"
}
```

Validation error (HTTP 422):

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field": ["message"]
  }
}
```

Unauthenticated (HTTP 401) / Forbidden (HTTP 403) follow the same `success: false` pattern without leaking internals.

## Phase 1 endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/health` | No | Service health |
| GET | `/auth/microsoft/redirect` | No | Start Entra SSO |
| GET | `/auth/microsoft/callback` | No | Entra callback |
| POST | `/auth/dev-login` | No* | Local fallback (`AUTH_DEV_LOGIN=true`) |
| POST | `/auth/logout` | Yes | Revoke current token |
| GET | `/auth/me` | Yes | Current user + roles/permissions |
| GET | `/dashboard` | Yes | Stub metrics (zeros until Phase 10) |
| GET | `/settings` | Yes (`settings.view`) | Public system settings |
| GET | `/mailbox/status` | Yes (`mailbox.sync` or `applications.view`) | Graph mailbox + sync status |
| POST | `/mailbox/test-connection` | Yes (`mailbox.sync`) | Test Graph token + inbox read |
| POST | `/mailbox/sync` | Yes (`mailbox.sync`) | Queue historical/incremental sync |
| POST | `/mailbox/sync/pause` | Yes (`mailbox.sync`) | Pause synchronization |
| POST | `/mailbox/sync/resume` | Yes (`mailbox.sync`) | Resume synchronization |
| GET | `/mailbox/logs` | Yes (`mailbox.sync`) | Paginated sync run logs |
| POST | `/mailbox/logs/{run}/retry` | Yes (`mailbox.sync`) | Retry after failed messages |

\* Disabled when `AUTH_DEV_LOGIN` is false.

## Authentication

Clients send: `Authorization: Bearer {token}`

Tokens are issued by Sanctum after Microsoft (or approved local) login.

## Future endpoint families

See architecture document for applications, mailbox sync, screening, reports, and audit routes.
