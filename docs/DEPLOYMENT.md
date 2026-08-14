# Deployment (outline)

Target: Ubuntu Linux + Nginx + PHP-FPM + MySQL 8 + Redis + Supervisor.

## Checklist

1. Deploy `backend/` and build `frontend/` (`npm run build`) served as static assets or separate host.
2. Set production `.env` (`APP_DEBUG=false`, `AUTH_DEV_LOGIN=false`, HTTPS URLs).
3. Configure Redis for `CACHE_STORE`, `QUEUE_CONNECTION=redis`, and Horizon.
4. Run `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`.
5. Supervisor workers: `php artisan horizon` (or `queue:work`).
6. Cron: `* * * * * php /path/to/artisan schedule:run`.
7. Private storage permissions on `storage/` and `bootstrap/cache/`.
8. Daily MySQL dumps + document storage backups with retention policy.

Full Nginx / Horizon samples will be expanded in Phase 12.
