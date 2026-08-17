# Deployment

Target: Ubuntu Linux + Nginx + PHP-FPM + MySQL 8 + Redis + Supervisor.

## Checklist

1. Deploy `backend/` and build `frontend/` (`npm run build`) served as static assets or a separate host.
2. Set production `.env` (`APP_DEBUG=false`, `AUTH_DEV_LOGIN=false`, HTTPS URLs).
3. Configure Redis for `CACHE_STORE`, `QUEUE_CONNECTION=redis`.
4. Run `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`.
5. Supervisor workers: `php artisan queue:work` (or Horizon if you add it later).
6. Cron: `* * * * * php /path/to/artisan schedule:run`.
7. Private storage permissions on `storage/` and `bootstrap/cache/`.
8. Daily MySQL dumps + document storage backups with retention policy.

## Nginx (API + built SPA)

Serve the React build from the same host or a second vhost. Example for the Laravel API:

```nginx
server {
    listen 443 ssl http2;
    server_name careers.nckenya.go.ke;
    root /var/www/nck-careers/backend/public;

    ssl_certificate     /etc/ssl/certs/nck-careers.crt;
    ssl_certificate_key /etc/ssl/private/nck-careers.key;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 180;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

If the SPA is a separate vhost, proxy `/api/` to Laravel and set `FRONTEND_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS`, and `CORS_ALLOWED_ORIGINS` to the HTTPS origin.

## Supervisor (queue worker)

```ini
[program:nck-careers-queue]
process_name=%(program_name)s
command=php /var/www/nck-careers/backend/artisan queue:work --queue=mail-sync,mail-import,default --sleep=1 --tries=5 --timeout=600 --memory=512
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/nck-careers-queue.log
stopwaitsecs=70
```

Mailbox-only alternative on the app server:

```bash
php artisan mailbox:queue-work
```

## Scheduler (cron)

```
* * * * * www-data php /var/www/nck-careers/backend/artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs include mailbox sync (15 min), attachment refill (1 min), mailbox-to-application conversion (5 min), and AI extraction when enabled (5 min).

## Production `.env` (must)

```
APP_ENV=production
APP_DEBUG=false
AUTH_DEV_LOGIN=false
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis
AI_ENABLED=false
AI_PROVIDER=mock
```

Turn `AI_ENABLED` on only after reviewing [AI processing](AI_PROCESSING.md). Do not put Graph or AI keys in the frontend env.

## Backups

- Nightly `mysqldump` of `nck_careers` with 14–30 day retention.
- Copy `backend/storage/app/private` (applicant documents) on the same cadence.
- Test a restore quarterly.
- Keep Entra app secrets in the server env or a secret store, not in git.

## Health

- Laravel: `GET /up`
- App: `GET /api/v1/health` (`database` should be `ok`)
