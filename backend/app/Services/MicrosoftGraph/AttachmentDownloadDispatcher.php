<?php

namespace App\Services\MicrosoftGraph;

use App\Jobs\DownloadMailAttachmentsJob;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use Illuminate\Support\Facades\DB;

class AttachmentDownloadDispatcher
{
    /**
     * Queue up to $limit messages for attachment / cloud-link download.
     */
    public function queueBatch(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));

        $this->reclaimStaleQueued();

        $messages = MailMessage::query()
            ->where('has_attachments', true)
            ->whereIn('attachments_status', ['pending', 'failed', 'partial'])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        if ($messages->isEmpty()) {
            return 0;
        }

        MailAttachment::query()
            ->whereIn('mail_message_id', $messages->pluck('id'))
            ->where('download_status', 'failed')
            ->update([
                'download_status' => 'pending',
                'error_message' => null,
            ]);

        foreach ($messages as $message) {
            $message->forceFill(['attachments_status' => 'queued'])->save();
            DownloadMailAttachmentsJob::dispatch($message->id);
        }

        return $messages->count();
    }

    /**
     * Queue specific message IDs (used by cloud-link reprocess).
     *
     * @param  list<int>  $messageIds
     */
    public function queueMessageIds(array $messageIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($ids === []) {
            return 0;
        }

        $messages = MailMessage::query()->whereIn('id', $ids)->get(['id']);
        foreach ($messages as $message) {
            $message->forceFill([
                'has_attachments' => true,
                'attachments_status' => 'queued',
            ])->save();
            DownloadMailAttachmentsJob::dispatch($message->id);
        }

        return $messages->count();
    }

    /**
     * Keep the mail-import queue topped up until every message is downloaded.
     */
    public function refillIfNeeded(int $batchSize = 100, int $lowWaterMark = 25): int
    {
        $remaining = MailMessage::query()
            ->where('has_attachments', true)
            ->whereIn('attachments_status', ['pending', 'failed', 'partial', 'queued'])
            ->count();

        if ($remaining === 0) {
            return 0;
        }

        $importJobs = (int) DB::table('jobs')->where('queue', 'mail-import')->count();

        if ($importJobs >= $lowWaterMark) {
            return 0;
        }

        return $this->queueBatch(max(1, $batchSize - $importJobs));
    }

    /**
     * @return array{with_attachments: int, done: int, remaining: int, percent: float}
     */
    public function progress(): array
    {
        $with = MailMessage::query()->where('has_attachments', true)->count();
        $done = MailMessage::query()
            ->where('has_attachments', true)
            ->whereIn('attachments_status', ['downloaded', 'none'])
            ->count();

        return [
            'with_attachments' => $with,
            'done' => $done,
            'remaining' => max(0, $with - $done),
            'percent' => $with > 0 ? round(min(100, ($done / $with) * 100), 1) : 100.0,
        ];
    }

    private function reclaimStaleQueued(): void
    {
        $importJobs = (int) DB::table('jobs')->where('queue', 'mail-import')->count();
        if ($importJobs === 0) {
            MailMessage::query()
                ->where('attachments_status', 'queued')
                ->update(['attachments_status' => 'pending']);

            return;
        }

        MailMessage::query()
            ->where('attachments_status', 'queued')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update(['attachments_status' => 'pending']);
    }
}
