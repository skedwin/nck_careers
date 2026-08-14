# NCK Careers — Architecture

## 1. System objective

Manage job applications received at **careers@nckenya.go.ke** via Microsoft Graph, with human-led screening, shortlisting, auditability, and optional AI-assisted extraction (never automated hiring decisions).

## 2. Logical architecture

```
┌─────────────────────────────────────────────────────────────┐
│  React SPA (TypeScript + Vite + Tailwind)                   │
│  Auth UI · Dashboard · Applications · Positions · Reports   │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTPS JSON /api/v1/*
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  Laravel 11 API                                             │
│  Sanctum · Policies · Form Requests · API Resources         │
│  ┌──────────────┐ ┌─────────────┐ ┌──────────────────────┐  │
│  │ Graph Services│ │ AI Services │ │ Screening Engine     │  │
│  │ (server-only) │ │ (abstracted)│ │ (rules + evidence) │  │
│  └──────────────┘ └─────────────┘ └──────────────────────┘  │
│  Jobs → Redis Queue → Horizon                               │
└───────┬──────────────┬──────────────┬───────────────────────┘
        ▼              ▼              ▼
     MySQL 8      Private disk    Microsoft Graph
                  (Azure Blob later)
```

## 3. Critical principles

1. **Web application only** — no mobile/desktop apps, no Filament admin, no Power Automate as core.
2. **MySQL is the system of record** — not Excel/SharePoint.
3. **Graph is read-only** in v1 — no delete/move/reply/mark-read of mailbox messages.
4. **Least privilege Graph access** — application permission scoped to `careers@nckenya.go.ke` only.
5. **AI assists humans** — extraction and recommendations; final decisions are human + audited.
6. **Server-side authorization** — frontend checks are UX only.

## 4. Project folder structure

```
nck-careers/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/V1/
│   │   ├── Http/Requests/
│   │   ├── Http/Resources/
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Jobs/                 # Phase 3+
│   │   ├── Services/
│   │   │   ├── MicrosoftGraph/   # Phase 2+
│   │   │   ├── AI/               # Phase 8
│   │   │   ├── Screening/        # Phase 7
│   │   │   └── Audit/
│   │   └── Support/
│   ├── config/
│   ├── database/migrations|seeders|factories
│   ├── routes/api.php
│   ├── storage/app/private/      # applicant documents
│   └── tests/
├── frontend/
│   ├── src/
│   │   ├── components/
│   │   ├── layouts/
│   │   ├── pages/
│   │   ├── features/
│   │   ├── lib/api.ts
│   │   ├── hooks/
│   │   └── routes/
│   └── ...
└── docs/
```

## 5. API route plan (`/api/v1`)

| Method | Path | Phase |
|--------|------|-------|
| GET | `/health` | 1 |
| GET | `/auth/microsoft/redirect` | 1 |
| GET | `/auth/microsoft/callback` | 1 |
| POST | `/auth/dev-login` | 1 (local only) |
| POST | `/auth/logout` | 1 |
| GET | `/auth/me` | 1 |
| GET | `/dashboard` | 1 (stub) → 10 |
| GET/POST | `/applications` … | 5+ |
| GET/POST | `/applicants` … | 5+ |
| GET/POST | `/positions` … | 6 |
| POST | `/mailbox/sync` | 3 |
| GET | `/mailbox/status` | 3 |
| GET | `/reports/*` | 10 |
| GET | `/audit-logs` | 11 |
| GET | `/settings` | 1/11 |

## 6. React route plan

| Path | Module | Phase |
|------|--------|-------|
| `/login` | Microsoft SSO (+ optional local) | 1 |
| `/dashboard` | Overview metrics | 1/10 |
| `/applications`, `/applications/:id` | Application list/detail | 5 |
| `/applicants`, `/applicants/:id` | Applicant profiles | 5 |
| `/positions` … | Vacancies & criteria | 6 |
| `/screening` | Screening workspace | 7 |
| `/shortlisting` | Shortlist workflow | 9 |
| `/documents` | Document browser | 4 |
| `/mailbox`, `/mailbox/sync`, `/mailbox/logs` | Mail admin | 3 |
| `/reports` | Reports & export | 10 |
| `/users` | User/role admin | 1/11 |
| `/settings` | System settings | 1/11 |
| `/audit-logs` | Audit trail | 11 |

## 7. Microsoft Graph integration plan

Services under `app/Services/MicrosoftGraph/`:

- `GraphAuthService` — client credentials token acquisition
- `GraphClient` — HTTP client, `$select`, pagination via `@odata.nextLink`
- `MailService` — list/get messages (read-only)
- `AttachmentService` — download attachments
- `SyncService` — historical batch import via queues
- `DeltaSyncService` — incremental sync using deltaLink

Webhooks are optional later; scheduled sync is the Phase 3 baseline.

## 8. Security model

| Control | Approach |
|---------|----------|
| Authentication | Microsoft Entra ID organizational accounts |
| API tokens/session | Laravel Sanctum |
| Authorization | Spatie roles/permissions + Policies |
| Secrets | Environment variables only |
| Documents | Private disk; authorized download endpoints |
| Audit | `audit_logs` for sensitive actions |
| Transport | HTTPS in production; secure cookies |
| Graph | App-only access restricted to careers mailbox |

Roles (seeded Phase 1):

- System Administrator
- Recruitment Administrator
- Recruitment Officer
- Recruitment Panel Member
- Reviewer
- Read Only
- Auditor

## 9. Implementation phases

1. **Foundation** — Laravel + React + MySQL + auth + layout + env *(this delivery)*
2. **Graph connection** — Entra app registration, token test, mock mode
3. **Mail sync** — historical + incremental + queues + duplicate protection
4. **Attachments** — private storage + classification
5. **Applicants/applications** — consolidation + profile UI
6. **Positions & criteria** — configurable eligibility
7. **Screening engine** — rules + evidence
8. **AI extraction** — provider abstraction + human review
9. **Shortlisting** — controlled status workflow
10. **Dashboard & reports** — live metrics + exports
11. **Audit & hardening** — security review
12. **Production deployment** — Nginx, Horizon, scheduler, backups

## 10. Assumptions (Phase 1)

1. Local PHP is **8.2.12** (XAMPP). Laravel 11 supports 8.2; production target remains **PHP 8.3+**. Spatie Permission v6 is used (v8 requires PHP 8.3).
2. Redis/Horizon are **not** required to run Phase 1 locally; queue uses the `database` driver until Redis is available.
3. `AUTH_DEV_LOGIN` provides a temporary local admin login so Phase 1 can be verified before Entra app credentials exist. It must be `false` outside local development.
4. Full domain migrations for applications/mail appear from Phase 3–5; Phase 1 ships users, RBAC, settings, audit, and notifications tables.
