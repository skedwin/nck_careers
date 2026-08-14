<?php

namespace App\Jobs;

use App\Models\MailMessage;
use App\Services\Applications\ApplicationIngestionService;
use App\Services\MicrosoftGraph\AttachmentDownloadDispatcher;
use App\Services\MicrosoftGraph\DownloadMailAttachmentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DownloadMailAttachmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Allow multi-attachment / large PDF messages without killing the worker. */
    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mailMessageId)
    {
        $this->onQueue('mail-import');
    }

    public function backoff(): array
    {
        return [15, 45, 90, 180];
    }

    public function handle(
        DownloadMailAttachmentsService $service,
        ApplicationIngestionService $ingestion,
    ): void {
        $message = MailMessage::query()->find($this->mailMessageId);
        if (! $message) {
            return;
        }

        $service->syncMessage($message);

        $fresh = $message->fresh();
        if ($fresh) {
            $ingestion->syncDocumentsFromMailMessage($fresh);
        }

        // Keep queue topped up so downloads continue in the background until complete.
        try {
            app(AttachmentDownloadDispatcher::class)->refillIfNeeded(100, 25);
        } catch (Throwable $e) {
            Log::warning('mail_attachments.refill_failed', [
                'mail_message_id' => $this->mailMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('mail_attachments.job_failed', [
            'mail_message_id' => $this->mailMessageId,
            'error' => $exception?->getMessage(),
        ]);

        $message = MailMessage::query()->find($this->mailMessageId);
        if (! $message) {
            return;
        }

        // Leave partial downloads as partial so refill can retry remaining files.
        if ($message->attachments_status === 'partial') {
            return;
        }

        if (! in_array($message->attachments_status, ['downloaded', 'none'], true)) {
            $message->forceFill(['attachments_status' => 'failed'])->save();
        }
    }
}
