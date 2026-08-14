# Microsoft Graph & Entra setup

## Registered application (NCK)

| Field | Value |
|-------|-------|
| Display name | NCK Careers Application System |
| Application (client) ID | `6d60cc6c-0a08-4f75-9833-dedc5d27728d` |
| Object ID | `193b4ec2-607e-4594-9cd2-2e42c8443e5d` |
| Directory (tenant) ID | `8eda8971-f11b-4217-bfa2-44a289dfca60` |
| Supported account types | My organization only |
| State | Activated |

Configured in `backend/.env` as `MICROSOFT_TENANT_ID` and `MICROSOFT_CLIENT_ID`.

**Do not commit client secrets.** Store `MICROSOFT_CLIENT_SECRET` only in server `.env`.

## Remaining Azure Portal steps

### 1. Client secret

Already configured locally in `.env` (`MICROSOFT_CLIENT_SECRET`). Rotate if exposed.

### 2. Add Redirect URI (staff SSO) — required to fix AADSTS500113

1. Open [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** → **App registrations**
2. Select **NCK Careers Application System** (`6d60cc6c-0a08-4f75-9833-dedc5d27728d`)
3. Left menu → **Authentication**
4. Click **Add a platform** → **Web** (or **Add URI** under Web)
5. Paste exactly (Entra accepts `http://localhost`, not `http://127.0.0.1`):

```text
http://localhost:8000/api/v1/auth/microsoft/callback
```

6. Under Implicit grant and hybrid flows, tick **ID tokens**
7. Click **Configure** / **Save**

The URI must match Laravel exactly. Entra **rejects** `http://127.0.0.1` for Web redirects; use `http://localhost` for local development.

### 3. API permissions

**Staff sign-in (delegated):**

- `openid`
- `profile`
- `email`
- `User.Read`

**Mailbox sync (application permissions — required for live Graph):**

1. App registration → **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**
2. Add **`Mail.Read`**
3. Click **Grant admin consent** for the NCK tenant
4. **Restrict** the application to mailbox `careers@nckenya.go.ke` only:

```powershell
# Exchange Online PowerShell (example)
New-ApplicationAccessPolicy -AppId 6d60cc6c-0a08-4f75-9833-dedc5d27728d `
  -PolicyScopeGroupId "careers@nckenya.go.ke" `
  -AccessRight RestrictAccess `
  -Description "NCK Careers mailbox only"
```

Prefer a mail-enabled security group that contains only the careers mailbox if required by your tenant policy.

Do **not** leave org-wide mailbox read enabled.

### 4. Verify Laravel config

```env
MICROSOFT_TENANT_ID=8eda8971-f11b-4217-bfa2-44a289dfca60
MICROSOFT_CLIENT_ID=6d60cc6c-0a08-4f75-9833-dedc5d27728d
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_REDIRECT_URI=http://localhost:8000/api/v1/auth/microsoft/callback
MICROSOFT_MAILBOX=careers@nckenya.go.ke
GRAPH_MOCK_MODE=true
```

## Phase 2 verification

```bash
cd backend
php artisan graph:test-connection
```

Or in the UI: **Mailbox → Test connection**.

- `GRAPH_MOCK_MODE=true` uses fixtures (no live Graph calls).
- Set `GRAPH_MOCK_MODE=false` only after `Mail.Read` + admin consent + mailbox restriction.

## Read-only mail policy (v1)

The application must not delete, move, mark as read, reply, or forward mailbox messages.

## Mock mode

`GRAPH_MOCK_MODE=true` uses fixtures under `backend/tests/Fixtures/Graph/` so development and CI do not need live Graph mailbox access.
