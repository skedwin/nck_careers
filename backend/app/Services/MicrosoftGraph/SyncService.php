<?php

namespace App\Services\MicrosoftGraph;

use App\Jobs\DownloadMailAttachmentsJob;
use App\Jobs\ImportMailMessageJob;
use App\Jobs\SyncMailboxJob;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\MailSyncError;
use App\Models\MailSyncRun;
use App\Models\MailSyncState;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\MicrosoftGraph\Exceptions\GraphException;
use App\Support\NairobiDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncService
{
    public function __construct(
        private readonly GraphAuthService $auth,
        private readonly MailService $mailService,
        private readonly DeltaSyncService $deltaSync,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function mailbox(): string
    {
        return $this->mailService->mailbox();
    }

    public function getOrCreateState(): MailSyncState
    {
        return MailSyncState::query()->firstOrCreate(
            ['mailbox' => $this->mailbox()],
            [
                'is_paused' => false,
                'initial_sync_completed' => false,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $state = $this->getOrCreateState();
        $latest = MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->latest('id')
            ->first();
        $running = MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->whereIn('status', [MailSyncRun::STATUS_PENDING, MailSyncRun::STATUS_RUNNING])
            ->latest('id')
            ->first();

        $inboxTotal = $this->estimateInboxTotal();
        $importedTotal = MailMessage::query()->where('mailbox', $this->mailbox())->count();
        $progressRun = $running ?: $latest;
        $attachmentsProgress = $this->attachmentsProgress();

        return [
            'mailbox' => $this->mailbox(),
            'mock_mode' => $this->auth->isMockMode(),
            'credentials_configured' => $this->auth->isConfigured(),
            'is_paused' => $state->is_paused,
            'initial_sync_completed' => $state->initial_sync_completed,
            'has_delta_link' => filled($state->delta_link),
            'last_successful_sync_at' => NairobiDate::iso($state->last_successful_sync_at),
            'messages_imported_total' => $importedTotal,
            'inbox_total_estimate' => $inboxTotal,
            'progress_percent' => $this->progressPercent($importedTotal, $inboxTotal),
            'can_continue' => $this->findResumableRun() !== null && ! $state->is_paused,
            'resumable_run' => ($resumable = $this->findResumableRun()) ? $this->serializeRun($resumable) : null,
            'active_run' => $running ? $this->serializeRun($running) : null,
            'latest_run' => $latest ? $this->serializeRun($latest) : null,
            'progress_run' => $progressRun ? $this->serializeRun($progressRun) : null,
            'attachments' => $attachmentsProgress,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentsProgress(): array
    {
        $withAttachments = MailMessage::query()
            ->where('mailbox', $this->mailbox())
            ->where('has_attachments', true)
            ->count();

        $byStatus = MailMessage::query()
            ->where('mailbox', $this->mailbox())
            ->where('has_attachments', true)
            ->selectRaw('attachments_status, COUNT(*) as total')
            ->groupBy('attachments_status')
            ->pluck('total', 'attachments_status')
            ->all();

        $done = (int) (($byStatus['downloaded'] ?? 0) + ($byStatus['none'] ?? 0));
        $queued = (int) (($byStatus['queued'] ?? 0));
        $pending = (int) (($byStatus['pending'] ?? 0));
        $partial = (int) (($byStatus['partial'] ?? 0));
        $failed = (int) (($byStatus['failed'] ?? 0));

        $filesDownloaded = MailAttachment::query()->where('download_status', 'downloaded')->count();
        $filesFailed = MailAttachment::query()->where('download_status', 'failed')->count();
        $filesSkipped = MailAttachment::query()->where('download_status', 'skipped')->count();

        return [
            'messages_with_attachments' => $withAttachments,
            'messages_done' => $done,
            'messages_pending' => $pending,
            'messages_queued' => $queued,
            'messages_partial' => $partial,
            'messages_failed' => $failed,
            'files_downloaded' => $filesDownloaded,
            'files_failed' => $filesFailed,
            'files_skipped' => $filesSkipped,
            'percent' => $withAttachments > 0
                ? round(min(100, ($done / $withAttachments) * 100), 1)
                : 100.0,
            'storage_disk' => 'private',
            'storage_path' => 'storage/app/private/mail-attachments/{message-uuid}/',
        ];
    }

    public function startSync(string $trigger = 'manual', ?User $user = null, ?string $forcedType = null): MailSyncRun
    {
        $state = $this->getOrCreateState();

        if ($state->is_paused) {
            throw new GraphException('Mailbox synchronization is paused.');
        }

        $active = MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->whereIn('status', [MailSyncRun::STATUS_PENDING, MailSyncRun::STATUS_RUNNING])
            ->exists();

        if ($active) {
            throw new GraphException('A mailbox synchronization run is already in progress.');
        }

        $type = $forcedType ?: $this->resolveSyncType($state);
        $inboxTotal = $this->estimateInboxTotal();

        $run = MailSyncRun::query()->create([
            'mailbox' => $this->mailbox(),
            'sync_type' => $type,
            'status' => MailSyncRun::STATUS_PENDING,
            'trigger' => $trigger,
            'initiated_by' => $user?->id,
            'meta' => [
                'graph_mock_mode' => $this->auth->isMockMode(),
                'strategy' => 'list',
                'inbox_total_estimate' => $inboxTotal,
                'page_size' => (int) config('services.microsoft_graph.page_size', 50),
            ],
        ]);

        $state->forceFill(['last_sync_run_id' => $run->id])->save();

        SyncMailboxJob::dispatch($run->id);

        $this->auditLogger->log('mailbox.sync_started', $run, null, [
            'sync_type' => $type,
            'trigger' => $trigger,
        ]);

        return $run;
    }

    /**
     * Continue a timed-out/failed/paused run from its saved next_link cursor.
     */
    public function continueSync(?User $user = null): MailSyncRun
    {
        $state = $this->getOrCreateState();

        if ($state->is_paused) {
            $state->forceFill(['is_paused' => false])->save();
        }

        $active = MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->whereIn('status', [MailSyncRun::STATUS_PENDING, MailSyncRun::STATUS_RUNNING])
            ->exists();

        if ($active) {
            throw new GraphException('A mailbox synchronization run is already in progress.');
        }

        $run = $this->findResumableRun();
        if (! $run || ! filled($run->next_link)) {
            throw new GraphException('No resumable sync cursor found. Start a full sync instead.');
        }

        $run->forceFill([
            'status' => MailSyncRun::STATUS_PENDING,
            'finished_at' => null,
            'error_summary' => null,
            'meta' => array_merge($run->meta ?? [], [
                'resumed_at' => now()->toIso8601String(),
                'resumed_by' => $user?->id,
            ]),
        ])->save();

        SyncMailboxJob::dispatch($run->id, (string) $run->next_link);

        $this->auditLogger->log('mailbox.sync_continued', $run, null, [
            'pages_processed' => $run->pages_processed,
            'messages_discovered' => $run->messages_discovered,
        ]);

        return $run->fresh();
    }

    public function pause(?User $user = null): MailSyncState
    {
        $state = $this->getOrCreateState();
        $state->forceFill(['is_paused' => true])->save();

        MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->whereIn('status', [MailSyncRun::STATUS_PENDING, MailSyncRun::STATUS_RUNNING])
            ->update([
                'status' => MailSyncRun::STATUS_PAUSED,
                'finished_at' => now(),
                'error_summary' => 'Synchronization paused by administrator.',
            ]);

        $this->auditLogger->log('mailbox.sync_paused', $state, null, [
            'paused_by' => $user?->id,
        ]);

        return $state->fresh();
    }

    public function resume(?User $user = null): MailSyncState
    {
        $state = $this->getOrCreateState();
        $state->forceFill(['is_paused' => false])->save();

        $this->auditLogger->log('mailbox.sync_resumed', $state, null, [
            'resumed_by' => $user?->id,
        ]);

        return $state->fresh();
    }

    public function logs(int $perPage = 15): LengthAwarePaginator
    {
        return MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->with('initiator:id,name,email')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Process one Graph page for a sync run. Invoked by SyncMailboxJob.
     */
    public function processNextPage(MailSyncRun $run): void
    {
        $state = $this->getOrCreateState();

        if ($state->is_paused || $run->status === MailSyncRun::STATUS_PAUSED) {
            $run->forceFill([
                'status' => MailSyncRun::STATUS_PAUSED,
                'finished_at' => now(),
            ])->save();

            return;
        }

        if ($run->status === MailSyncRun::STATUS_PENDING) {
            $run->forceFill([
                'status' => MailSyncRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?: now(),
            ])->save();
        }

        try {
            $page = $this->fetchPage($run, $state);
            $messages = $page['value'] ?? [];
            if (! is_array($messages)) {
                $messages = [];
            }

            $run->increment('pages_processed');
            $run->increment('messages_discovered', count($messages));

            $jobs = [];
            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }

                // Delta payloads can include soft-deleted tombstones.
                if (isset($message['@removed'])) {
                    continue;
                }

                if (empty($message['id'])) {
                    continue;
                }

                $this->importMessage($run, $message);
            }

            $run = $run->fresh();
            $nextLink = $page['@odata.nextLink'] ?? null;
            $deltaLink = $page['@odata.deltaLink'] ?? null;

            if (filled($deltaLink)) {
                $run->forceFill(['delta_link' => $deltaLink])->save();
                $this->deltaSync->storeDeltaLink($this->mailbox(), (string) $deltaLink);
            }

            if (filled($nextLink)) {
                $run->forceFill(['next_link' => $nextLink])->save();

                // Keep sync-driver tests shallow; production uses async queue workers.
                if (config('queue.default') === 'sync') {
                    $this->processNextPage($run->fresh());

                    return;
                }

                $this->dispatchNextPage($run->id, (string) $nextLink);

                return;
            }

            $this->completeRun($run->fresh(), $state);
        } catch (Throwable $e) {
            $this->failRun($run, $e);
            throw $e;
        }
    }

    public function completeRunPublic(MailSyncRun $run): void
    {
        $this->completeRun($run, $this->getOrCreateState());
    }

    public function dispatchNextPage(int $runId, ?string $nextLink = null): void
    {
        SyncMailboxJob::dispatch($runId, $nextLink);
    }

    /**
     * Idempotent import used by ImportMailMessageJob.
     *
     * @param  array<string, mixed>  $payload
     * @return string imported|skipped|failed
     */
    public function importMessage(MailSyncRun $run, array $payload): string
    {
        $graphId = (string) ($payload['id'] ?? '');
        if ($graphId === '') {
            $this->recordError($run, null, 'Missing Graph message id.', ['payload_keys' => array_keys($payload)]);
            $run->increment('messages_failed');

            return 'failed';
        }

        if (MailMessage::query()->where('graph_message_id', $graphId)->exists()) {
            $run->increment('messages_skipped');

            return 'skipped';
        }

        $internetId = $payload['internetMessageId'] ?? null;
        $internetId = is_string($internetId) && trim($internetId) !== '' ? trim($internetId) : null;

        if ($internetId && MailMessage::query()->where('internet_message_id', $internetId)->exists()) {
            $run->increment('messages_skipped');

            return 'skipped';
        }

        try {
            $hasAttachments = (bool) ($payload['hasAttachments'] ?? false);
            $bodyPayload = $payload;

            // List pages omit body; fetch full message so Drive/SharePoint links can be captured.
            if (! is_array(data_get($payload, 'body')) || ! filled(data_get($payload, 'body.content'))) {
                try {
                    $bodyPayload = array_merge($payload, $this->mailService->getMessage($graphId, true));
                } catch (Throwable $e) {
                    // Keep preview-only import; attachment job may refresh body later.
                }
            }

            $bodyHtml = null;
            $bodyText = $payload['bodyPreview'] ?? null;
            $content = data_get($bodyPayload, 'body.content');
            $contentType = strtolower((string) data_get($bodyPayload, 'body.contentType', ''));
            if (is_string($content) && trim($content) !== '') {
                if ($contentType === 'html') {
                    $bodyHtml = $content;
                    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5));
                    if ($plain !== '') {
                        $bodyText = mb_substr($plain, 0, 5000);
                    }
                } else {
                    $bodyText = mb_substr($content, 0, 5000);
                }
            }

            $cloudLinks = app(CloudLinkExtractor::class)->extract($bodyHtml, is_string($bodyText) ? $bodyText : null);
            if ($cloudLinks !== []) {
                $hasAttachments = true;
            }

            /** @var MailMessage|null $created */
            $created = null;

            DB::transaction(function () use ($run, $payload, $graphId, $internetId, $hasAttachments, $bodyHtml, $bodyText, &$created): void {
                $created = MailMessage::query()->create([
                    'graph_message_id' => $graphId,
                    'internet_message_id' => $internetId,
                    'conversation_id' => $payload['conversationId'] ?? null,
                    'mailbox' => $run->mailbox,
                    'sender_name' => data_get($payload, 'from.emailAddress.name'),
                    'sender_email' => strtolower((string) data_get($payload, 'from.emailAddress.address')),
                    'subject' => $payload['subject'] ?? null,
                    'received_at' => NairobiDate::utcForStorage(
                        isset($payload['receivedDateTime']) ? (string) $payload['receivedDateTime'] : null
                    ),
                    'body_text' => $bodyText,
                    'body_html' => $bodyHtml,
                    'has_attachments' => $hasAttachments,
                    'web_link' => $payload['webLink'] ?? null,
                    'to_recipients' => $payload['toRecipients'] ?? [],
                    'cc_recipients' => $payload['ccRecipients'] ?? [],
                    'sync_status' => 'imported',
                    'attachments_status' => $hasAttachments ? 'pending' : 'none',
                    'application_created' => false,
                    'mail_sync_run_id' => $run->id,
                ]);
            });

            if ($created && $hasAttachments) {
                DownloadMailAttachmentsJob::dispatch($created->id);
            }

            $run->increment('messages_imported');

            return 'imported';
        } catch (QueryException $e) {
            // Race-safe duplicate protection.
            if ($this->isUniqueViolation($e)) {
                $run->increment('messages_skipped');

                return 'skipped';
            }

            $this->recordError($run, $graphId, $e->getMessage());
            $run->increment('messages_failed');

            return 'failed';
        } catch (Throwable $e) {
            $this->recordError($run, $graphId, $e->getMessage());
            $run->increment('messages_failed');

            return 'failed';
        }
    }

    public function retryFailed(MailSyncRun $run): MailSyncRun
    {
        if (filled($run->next_link) && in_array($run->status, [
            MailSyncRun::STATUS_FAILED,
            MailSyncRun::STATUS_PAUSED,
        ], true)) {
            return $this->continueSync();
        }

        return $this->startSync('manual', null, $run->sync_type === 'incremental' ? 'incremental' : 'initial');
    }

    private function resolveSyncType(MailSyncState $state): string
    {
        if (! $state->initial_sync_completed) {
            return 'initial';
        }

        return filled($state->delta_link) ? 'incremental' : 'initial';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(MailSyncRun $run, MailSyncState $state): array
    {
        if (filled($run->next_link)) {
            return $this->mailService->getByNextLink((string) $run->next_link);
        }

        if ($run->sync_type === 'incremental') {
            return $this->fetchIncrementalPage($state);
        }

        // Historical backfill: list pagination (messages/delta only returns ~1 page for this mailbox).
        return $this->mailService->listInboxMessages(
            (int) config('services.microsoft_graph.page_size', 50)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchIncrementalPage(MailSyncState $state): array
    {
        // Prefer delta when we already hold a deltaLink from a completed list backfill anchor attempt.
        if (filled($state->delta_link)) {
            try {
                return $this->deltaSync->fetchDeltaPage((string) $state->delta_link);
            } catch (Throwable $e) {
                // Fall through to receivedDateTime filter.
            }
        }

        $since = $state->last_successful_sync_at
            ?: MailMessage::query()->where('mailbox', $this->mailbox())->max('received_at');

        $pageSize = (int) config('services.microsoft_graph.page_size', 50);

        if (! $since) {
            return $this->mailService->listInboxMessages($pageSize);
        }

        $iso = $since instanceof \DateTimeInterface
            ? $since->format('Y-m-d\TH:i:s\Z')
            : \Carbon\Carbon::parse((string) $since)->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->mailService->listInboxMessages($pageSize, [
            '$filter' => "receivedDateTime ge {$iso}",
        ]);
    }

    private function completeRun(MailSyncRun $run, MailSyncState $state): void
    {
        // Best-effort: capture a deltaLink after list backfill for future incremental syncs.
        if (! filled($run->delta_link) && ! filled($state->delta_link)) {
            try {
                $page = $this->deltaSync->startDelta();
                $guard = 0;
                while (filled($page['@odata.nextLink'] ?? null) && $guard < 5) {
                    $page = $this->mailService->getByNextLink((string) $page['@odata.nextLink']);
                    $guard++;
                }
                if (filled($page['@odata.deltaLink'] ?? null)) {
                    $run->forceFill(['delta_link' => $page['@odata.deltaLink']])->save();
                    $this->deltaSync->storeDeltaLink($this->mailbox(), (string) $page['@odata.deltaLink']);
                    $state->refresh();
                }
            } catch (Throwable) {
                // List + receivedDateTime filter remains the fallback for incremental sync.
            }
        }

        $run->forceFill([
            'status' => MailSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'next_link' => null,
            'error_summary' => null,
        ])->save();

        $state->forceFill([
            'initial_sync_completed' => true,
            'last_sync_run_id' => $run->id,
            'last_successful_sync_at' => now(),
            'delta_link' => $run->delta_link ?: $state->delta_link,
        ])->save();

        $this->auditLogger->log('mailbox.sync_completed', $run, null, [
            'imported' => $run->messages_imported,
            'skipped' => $run->messages_skipped,
            'failed' => $run->messages_failed,
        ]);
    }

    private function failRun(MailSyncRun $run, Throwable $e): void
    {
        // Keep next_link so the UI can Continue sync after timeouts.
        $run->forceFill([
            'status' => MailSyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_summary' => mb_substr($e->getMessage(), 0, 2000),
            'meta' => array_merge($run->meta ?? [], [
                'resumable' => filled($run->next_link),
                'failed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->recordError($run, null, $e->getMessage(), [
            'stage' => 'page',
            'resumable' => filled($run->next_link),
        ], 'page');

        $this->auditLogger->log('mailbox.sync_failed', $run, null, [
            'error' => $e->getMessage(),
            'resumable' => filled($run->next_link),
        ]);
    }

    private function findResumableRun(): ?MailSyncRun
    {
        return MailSyncRun::query()
            ->where('mailbox', $this->mailbox())
            ->whereIn('status', [MailSyncRun::STATUS_FAILED, MailSyncRun::STATUS_PAUSED])
            ->whereNotNull('next_link')
            ->latest('id')
            ->first();
    }

    private function estimateInboxTotal(): ?int
    {
        return cache()->remember('mailbox_inbox_total_'.$this->mailbox(), now()->addMinutes(5), function () {
            try {
                $inbox = $this->mailService->getInboxFolder();
                $total = $inbox['totalItemCount'] ?? null;

                return is_numeric($total) ? (int) $total : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    private function progressPercent(int $imported, ?int $inboxTotal): ?float
    {
        if (! $inboxTotal || $inboxTotal < 1) {
            return null;
        }

        return round(min(100, ($imported / $inboxTotal) * 100), 1);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function recordError(
        MailSyncRun $run,
        ?string $graphMessageId,
        string $message,
        ?array $context = null,
        string $stage = 'import',
    ): void {
        MailSyncError::query()->create([
            'mail_sync_run_id' => $run->id,
            'graph_message_id' => $graphMessageId,
            'stage' => $stage,
            'error_message' => mb_substr($message, 0, 2000),
            'context' => $context,
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(MailSyncRun $run): array
    {
        $inboxEstimate = (int) data_get($run->meta, 'inbox_total_estimate', 0);

        return [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'sync_type' => $run->sync_type,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'messages_discovered' => $run->messages_discovered,
            'messages_imported' => $run->messages_imported,
            'messages_skipped' => $run->messages_skipped,
            'messages_failed' => $run->messages_failed,
            'pages_processed' => $run->pages_processed,
            'error_summary' => $run->error_summary,
            'has_resume_cursor' => filled($run->next_link),
            'inbox_total_estimate' => $inboxEstimate > 0 ? $inboxEstimate : null,
            'progress_percent' => $inboxEstimate > 0
                ? round(min(100, (MailMessage::query()->where('mailbox', $run->mailbox)->count() / $inboxEstimate) * 100), 1)
                : null,
        ];
    }
}
