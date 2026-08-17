# Installation

## Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Configure MySQL in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nck_careers
DB_USERNAME=root
DB_PASSWORD=your_password
```

Create database:

```sql
CREATE DATABASE nck_careers CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Migrate and seed:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

## Frontend

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

Set `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1`.

## AI extraction (optional)

Leave `AI_PROVIDER=mock` and `AI_ENABLED=false` unless you have an OpenAI or Azure OpenAI key. Officers can still run a system assessment from an application. See [AI processing](AI_PROCESSING.md).

## Local auth for Phase 1

Set `AUTH_DEV_LOGIN=true` and use the seeded System Administrator credentials documented in the README.
