# NCK Careers Application Management System

Secure web application for the Nursing Council of Kenya to manage recruitment applications received at `careers@nckenya.go.ke`.

## Architecture (high level)

```
React SPA (frontend/)
    |  HTTPS / JSON REST API
    v
Laravel API (backend/)
    +---- MySQL 8
    +---- Redis / Queue / Horizon (production)
    +---- Microsoft Graph API (server-side only)
    +---- Private document storage
    +---- AI provider abstraction
```

**React never talks to Microsoft Graph.** All Graph credentials and tokens stay on Laravel.

## Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 11, PHP 8.2+ (8.3+ preferred in production) |
| Frontend | React 18, TypeScript, Vite, Tailwind CSS |
| API auth | Laravel Sanctum + Microsoft Entra ID SSO |
| RBAC | Spatie Laravel Permission |
| Database | MySQL 8 |
| Queue | Redis + Horizon (database driver acceptable locally) |

## Repository layout

```
nck-careers/
├── backend/          # Laravel API
├── frontend/         # React SPA
├── docs/             # Architecture & operations docs
└── README.md
```

## Phase status

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Project foundation, MySQL, Auth, base UI | Complete |
| 2 | Entra / Graph mailbox connection | Complete |
| 3 | Historical + incremental mail sync | Complete |
| 4 | Attachments + private storage | **Complete** |
| 5–6 | Applications, applicants, positions | **Complete (MVP)** |
| 7–9 | Screening, AI extraction + human review, shortlisting | **Complete (MVP)** |
| 10–11 | Dashboard, reports, users, settings, audit | **Complete (MVP)** |
| 12 | Production hardening | **Complete (docs + scheduler + health)** |

Timezone: Graph/`received_at` are UTC; API and UI present **Africa/Nairobi (EAT, UTC+3)**.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full plan.

## Local setup (Phase 1)

### Prerequisites

- PHP 8.2+ with extensions: `openssl`, `pdo_mysql`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`
- Composer 2
- Node.js 20+
- MySQL 8

### 1. Backend

```bash
cd backend
cp .env.example .env
# Set DB_* and APP_KEY
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

API base URL: `http://127.0.0.1:8000`

Health check: `GET /api/v1/health`

### 2. Frontend

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

UI: `http://127.0.0.1:5173`

### Development login (local only)

When `AUTH_DEV_LOGIN=true` in `backend/.env`, you can sign in with the seeded admin:

- Email: `admin@nckenya.go.ke`
- Password: `ChangeMeNow!123`

**Disable `AUTH_DEV_LOGIN` in every non-local environment.** Production authentication is Microsoft Entra ID only.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Database](docs/DATABASE.md)
- [API](docs/API.md)
- [Installation](docs/INSTALLATION.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Microsoft Graph setup](docs/MICROSOFT_GRAPH_SETUP.md)
- [Security](docs/SECURITY.md)
- [Mail sync](docs/MAIL_SYNC.md)
- [AI processing](docs/AI_PROCESSING.md)

## Security notes

- Never commit `.env` files or secrets
- Never expose Graph tokens or client secrets to the React app
- Applicant documents are private and served only through authorized API endpoints
