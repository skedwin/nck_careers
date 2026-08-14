<?php

namespace App\Jobs;

use App\Models\MailSyncRun;
use App\Services\MicrosoftGraph\SyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportMailMessageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    public function __construct(
        public readonly int $mailSyncRunId,
        public readonly array $messagePayload,
    ) {
        $this->onQueue('mail-import');
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(SyncService $syncService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = MailSyncRun::query()->find($this->mailSyncRunId);
        if (! $run) {
            return;
        }

        $syncService->importMessage($run, $this->messagePayload);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('mail_import.job_failed', [
            'mail_sync_run_id' => $this->mailSyncRunId,
            'graph_message_id' => $this->messagePayload['id'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
