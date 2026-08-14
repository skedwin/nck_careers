<?php

namespace App\Jobs;

use App\Models\MailSyncRun;
use App\Services\MicrosoftGraph\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public int $timeout = 240;

    public function __construct(
        public readonly int $mailSyncRunId,
        public readonly ?string $nextLink = null,
    ) {
        $this->onQueue('mail-sync');
    }

    public function backoff(): array
    {
        return [15, 30, 60, 120, 180, 300];
    }

    public function handle(SyncService $syncService): void
    {
        $run = MailSyncRun::query()->find($this->mailSyncRunId);
        if (! $run) {
            return;
        }

        if (in_array($run->status, [MailSyncRun::STATUS_COMPLETED, MailSyncRun::STATUS_CANCELLED], true)) {
            return;
        }

        if ($this->nextLink) {
            $run->forceFill(['next_link' => $this->nextLink])->save();
        }

        $syncService->processNextPage($run->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('mail_sync.job_failed', [
            'mail_sync_run_id' => $this->mailSyncRunId,
            'error' => $exception?->getMessage(),
        ]);

        $run = MailSyncRun::query()->find($this->mailSyncRunId);
        if ($run && $run->isStoppable()) {
            $run->forceFill([
                'status' => MailSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_summary' => $exception?->getMessage(),
                'meta' => array_merge($run->meta ?? [], [
                    'resumable' => filled($run->next_link),
                ]),
            ])->save();
        }
    }
}
