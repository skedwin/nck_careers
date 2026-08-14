# Security

- HTTPS only in production
- Sanctum bearer tokens / secure cookies
- Server-side Policies for every sensitive action
- Secrets only in environment configuration
- Private document storage; no public CV paths
- Audit logging for view/download/status/auth events
- Rate limiting on auth and sync endpoints
- No stack traces or secrets in API error payloads
- Graph tokens never returned to React
- `AUTH_DEV_LOGIN` must be false outside local development
- Data minimization for applicant PII
- Retention/archival settings without silent permanent deletion
