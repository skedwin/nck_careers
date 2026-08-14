<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Services\Audit\AuditLogger;
use App\Services\MicrosoftGraph\AttachmentDownloadDispatcher;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailAttachmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AttachmentDownloadDispatcher $dispatcher,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = MailAttachment::query()
            ->with(['mailMessage:id,uuid,subject,sender_email,received_at,attachments_status'])
            ->latest('id');

        if ($status = $request->query('download_status')) {
            $query->where('download_status', $status);
        }

        if ($messageId = $request->query('mail_message_id')) {
            $query->where('mail_message_id', $messageId);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (MailAttachment $attachment) => [
            'id' => $attachment->id,
            'uuid' => $attachment->uuid,
            'mail_message_id' => $attachment->mail_message_id,
            'name' => $attachment->name,
            'content_type' => $attachment->content_type,
            'size' => $attachment->size,
            'is_inline' => $attachment->is_inline,
            'source' => $attachment->source,
            'provider' => $attachment->provider,
            'external_url' => $attachment->external_url,
            'download_status' => $attachment->download_status,
            'error_message' => $attachment->error_message,
            'created_at' => NairobiDate::iso($attachment->created_at),
            'updated_at' => NairobiDate::iso($attachment->updated_at),
            'mail_message' => $attachment->mailMessage ? [
                'id' => $attachment->mailMessage->id,
                'uuid' => $attachment->mailMessage->uuid,
                'subject' => $attachment->mailMessage->subject,
                'sender_email' => $attachment->mailMessage->sender_email,
                'received_at' => NairobiDate::iso($attachment->mailMessage->received_at),
                'attachments_status' => $attachment->mailMessage->attachments_status,
            ] : null,
        ]);

        return ApiResponse::success($paginator);
    }

    public function download(MailAttachment $attachment): StreamedResponse
    {
        $disk = $attachment->disk ?: 'private';
        $path = $attachment->path;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Attachment file not found.');
        }

        return Storage::disk($disk)->download(
            $path,
            $attachment->name ?: basename($path),
            array_filter([
                'Content-Type' => $attachment->content_type,
            ])
        );
    }

    public function queueDownloads(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'until_done' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $queued = $this->dispatcher->queueBatch($limit);

        // Self-refill chain: workers will keep topping up via DownloadMailAttachmentsJob.
        if ($request->boolean('until_done', true) && $queued > 0) {
            $this->dispatcher->refillIfNeeded($limit, 50);
        }

        $progress = $this->dispatcher->progress();

        $this->auditLogger->log('mailbox.attachments_download_queued', null, null, [
            'queued' => $queued,
            'limit' => $limit,
            'until_done' => true,
            'progress' => $progress,
        ], $request);

        return ApiResponse::success([
            'queued' => $queued,
            'limit' => $limit,
            'until_done' => true,
            'progress' => $progress,
        ], $queued > 0
            ? 'Attachment download jobs queued. Workers will keep topping up until complete.'
            : 'No pending attachment downloads.');
    }
}