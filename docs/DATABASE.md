# Database design

## Phase 1–3 tables (implemented)

- `users`, RBAC, `system_settings`, `audit_logs`, `notifications`
- `mail_messages` — imported Graph messages (unique `graph_message_id`, `internet_message_id`)
- `mail_sync_runs` — sync execution records and counters
- `mail_sync_errors` — per-message/page failures
- `mail_sync_states` — pause flag, deltaLink, last success

## Planned domain tables (Phases 3–8)

```
positions ──┬── position_criteria
            │
applicants ─┬── applications ──┬── application_documents
            │                  ├── qualifications
            │                  ├── employment_histories
            │                  ├── professional_registrations
            │                  ├── screening_results / screening_criteria
            │                  └── application_status_history
            │
mail_messages ── mail_attachments
mail_sync_runs ── mail_sync_errors
ai_extractions
```

## ERD (conceptual)

```
users ──< audit_logs
users ──< notifications
users >──< roles >──< permissions

positions ──< position_criteria
positions ──< applications

applicants ──< applications
applicants ──< qualifications
applicants ──< employment_histories
applicants ──< professional_registrations

mail_messages ──< mail_attachments
mail_messages ── applications (1:1 optional)

applications ──< application_documents
applications ──< screening_results
applications ──< application_status_history
applications ──< ai_extractions

mail_sync_runs ──< mail_sync_errors
```

## Uniqueness & scale indexes (planned)

| Table | Unique / index |
|-------|----------------|
| mail_messages | unique `graph_message_id`; unique nullable `internet_message_id`; index `received_at` |
| mail_attachments | unique (`mail_message_id`,`graph_attachment_id`); index `sha256_hash` |
| applications | unique `uuid`; unique `application_reference`; indexes on status, received_at, position_id |
| applicants | unique `uuid`; index `email`; index `registration_number` |

## Performance targets

Designed for ≥ 10k applicants, 50k emails, 100k documents via pagination, queues, and selective eager loading — no full-table browser loads.
