# Mail synchronization

## Modes

| Mode | Description |
|------|-------------|
| Initial | Full Inbox walk via `@odata.nextLink` until exhausted |
| Incremental | Graph delta query using stored `deltaLink` |
| Manual | Admin **Sync Now** / `php artisan mailbox:sync` |
| Scheduled | Every 15 minutes via Laravel scheduler |
| Pause / Resume | Administrators can pause queue progression |

## Pipeline

```
POST /api/v1/mailbox/sync
    → SyncMailboxJob (queue: mail-sync)
        → fetch one Graph page
        → Bus::batch(ImportMailMessageJob...) (queue: mail-import)
            → on batch finally: next SyncMailboxJob OR complete run
```

Jobs are retryable with backoff. Imports are idempotent (`graph_message_id`, `internet_message_id` unique indexes).

## Commands

```bash
php artisan mailbox:sync
php artisan graph:test-connection
php artisan queue:work --queue=mail-sync,mail-import,default
php artisan schedule:work
```

## Logging

Stored in `mail_sync_runs` and `mail_sync_errors`.

API:

- `GET /api/v1/mailbox/status`
- `POST /api/v1/mailbox/sync`
- `POST /api/v1/mailbox/sync/pause`
- `POST /api/v1/mailbox/sync/resume`
- `GET /api/v1/mailbox/logs`

## Read-only policy

Sync never deletes, moves, marks as read, replies to, or modifies mailbox messages in Microsoft 365.
