<?php

namespace App\Services\MicrosoftGraph;

use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Services\MicrosoftGraph\Exceptions\GraphException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DownloadMailAttachmentsService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly CloudLinkExtractor $links,
        private readonly MailService $mail,
    ) {
    }

    /**
     * @return array{downloaded: int, skipped: int, failed: int, link_only: int}
     */
    public function syncMessage(MailMessage $message): array
    {
        $counts = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'link_only' => 0];

        $this->ensureMessageBody($message);

        $graphItems = [];
        if ($message->has_attachments || filled($message->graph_message_id)) {
            try {
                $payload = $this->attachments->listAttachments((string) $message->graph_message_id);
                $graphItems = is_array($payload['value'] ?? null) ? $payload['value'] : [];
            } catch (Throwable $e) {
                // Still continue with body-link extraction for cloud docs.
                Log::warning('mail_attachments.list_failed', [
                    'mail_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $disk = 'private';

        foreach ($graphItems as $item) {
            if (! is_array($item) || empty($item['id'])) {
                continue;
            }

            $result = $this->persistGraphAttachment($message, $item, $disk);
            $counts[$result]++;
        }

        foreach ($this->links->extract($message->body_html, $message->body_text) as $link) {
            $result = $this->persistBodyLink($message, $link, $disk);
            $counts[$result]++;
        }

        $total = array_sum($counts);
        if ($total > 0 && ! $message->has_attachments) {
            $message->has_attachments = true;
        }

        if ($total === 0) {
            $message->forceFill([
                'attachments_status' => $message->has_attachments ? 'failed' : 'none',
            ])->save();

            return $counts;
        }

        $message->forceFill([
            'has_attachments' => true,
            'attachments_status' => $this->resolveMessageStatus($counts),
        ])->save();

        return $counts;
    }

    /**
     * Refresh body from Graph when missing (needed for Drive/SharePoint links in HTML).
     */
    public function ensureMessageBody(MailMessage $message): void
    {
        if (filled($message->body_html) || ! filled($message->graph_message_id)) {
            return;
        }

        try {
            $payload = $this->mail->getMessage((string) $message->graph_message_id, true);
            $this->applyBodyFromGraphPayload($message, $payload);
            $message->save();
        } catch (Throwable $e) {
            Log::warning('mail_attachments.body_refresh_failed', [
                'mail_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyBodyFromGraphPayload(MailMessage $message, array $payload): void
    {
        $content = data_get($payload, 'body.content');
        $contentType = strtolower((string) data_get($payload, 'body.contentType', ''));

        if (is_string($content) && trim($content) !== '') {
            if ($contentType === 'html') {
                $message->body_html = $content;
                $text = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5));
                if ($text !== '') {
                    $message->body_text = Str::limit($text, 5000, '');
                }
            } else {
                $message->body_text = Str::limit($content, 5000, '');
            }
        }

        if (! filled($message->body_text) && filled($payload['bodyPreview'] ?? null)) {
            $message->body_text = (string) $payload['bodyPreview'];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return 'downloaded'|'skipped'|'failed'|'link_only'
     */
    private function persistGraphAttachment(MailMessage $message, array $item, string $disk): string
    {
        $graphAttachmentId = (string) $item['id'];
        $name = (string) ($item['name'] ?? 'attachment');
        $contentType = isset($item['contentType']) ? (string) $item['contentType'] : null;
        $size = (int) ($item['size'] ?? 0);
        $isInline = (bool) ($item['isInline'] ?? false);
        $odataType = (string) ($item['@odata.type'] ?? '');
        $type = strtolower($odataType);

        /** @var MailAttachment $attachment */
        $attachment = MailAttachment::query()->firstOrNew([
            'mail_message_id' => $message->id,
            'graph_attachment_id' => $graphAttachmentId,
        ]);

        $isReference = str_contains($type, 'referenceattachment');
        $sourceUrl = $isReference
            ? (string) ($item['sourceUrl'] ?? $item['SourceUrl'] ?? '')
            : '';

        $provider = $sourceUrl !== '' ? $this->links->detectProvider($sourceUrl) : null;

        $attachment->fill([
            'name' => $name !== '' ? $name : ($provider ? ucfirst(str_replace('_', ' ', $provider)).' document' : 'attachment'),
            'content_type' => $contentType,
            'size' => $size,
            'is_inline' => $isInline,
            'disk' => $disk,
            'source' => $isReference ? MailAttachment::SOURCE_GRAPH_REFERENCE : MailAttachment::SOURCE_GRAPH_FILE,
            'provider' => $provider,
            'external_url' => $sourceUrl !== '' ? $sourceUrl : $attachment->external_url,
            'odata_type' => $odataType !== '' ? $odataType : null,
        ]);

        if ($attachment->download_status === MailAttachment::STATUS_DOWNLOADED && filled($attachment->path)) {
            $attachment->save();

            return 'downloaded';
        }

        if ($isInline && ! $isReference) {
            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_SKIPPED,
                'error_message' => 'Inline attachment skipped.',
                'path' => null,
                'sha256_hash' => null,
            ])->save();

            return 'skipped';
        }

        if ($isReference) {
            return $this->persistCloudTarget($attachment, $message, $disk, $sourceUrl, $provider);
        }

        if ($odataType !== '' && ! str_contains($type, 'fileattachment')) {
            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_SKIPPED,
                'error_message' => 'Non-file attachment skipped.',
                'path' => null,
                'sha256_hash' => null,
            ])->save();

            return 'skipped';
        }

        return $this->downloadGraphFile($attachment, $message, $graphAttachmentId, $disk, $size, $contentType);
    }

    /**
     * @param  array{url: string, provider: string, name: string}  $link
     * @return 'downloaded'|'skipped'|'failed'|'link_only'
     */
    private function persistBodyLink(MailMessage $message, array $link, string $disk): string
    {
        $graphId = $this->links->syntheticGraphId($link['url']);

        /** @var MailAttachment $attachment */
        $attachment = MailAttachment::query()->firstOrNew([
            'mail_message_id' => $message->id,
            'graph_attachment_id' => $graphId,
        ]);

        // Prefer an existing Graph reference row for the same URL.
        $existingRef = MailAttachment::query()
            ->where('mail_message_id', $message->id)
            ->where('external_url', $link['url'])
            ->where('source', MailAttachment::SOURCE_GRAPH_REFERENCE)
            ->first();

        if ($existingRef) {
            return match ($existingRef->download_status) {
                MailAttachment::STATUS_DOWNLOADED => 'downloaded',
                MailAttachment::STATUS_LINK_ONLY => 'link_only',
                MailAttachment::STATUS_FAILED => 'failed',
                MailAttachment::STATUS_SKIPPED => 'skipped',
                default => 'link_only',
            };
        }

        $attachment->fill([
            'name' => $link['name'],
            'content_type' => null,
            'size' => (int) ($attachment->size ?? 0),
            'is_inline' => false,
            'disk' => $disk,
            'source' => MailAttachment::SOURCE_BODY_LINK,
            'provider' => $link['provider'],
            'external_url' => $link['url'],
            'odata_type' => null,
        ]);

        if ($attachment->download_status === MailAttachment::STATUS_DOWNLOADED && filled($attachment->path)) {
            $attachment->save();

            return 'downloaded';
        }

        return $this->persistCloudTarget($attachment, $message, $disk, $link['url'], $link['provider']);
    }

    /**
     * @return 'downloaded'|'failed'|'link_only'
     */
    private function persistCloudTarget(
        MailAttachment $attachment,
        MailMessage $message,
        string $disk,
        ?string $url,
        ?string $provider,
    ): string {
        $url = trim((string) $url);
        if ($url === '') {
            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_LINK_ONLY,
                'error_message' => 'Cloud attachment has no source URL.',
                'path' => null,
            ])->save();

            return 'link_only';
        }

        $attachment->external_url = $url;
        $attachment->provider = $provider ?: $this->links->detectProvider($url);

        if (! $this->links->isMicrosoftCloud($attachment->provider)) {
            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_LINK_ONLY,
                'error_message' => null,
                'path' => null,
                'sha256_hash' => null,
            ])->save();

            return 'link_only';
        }

        try {
            if (! $attachment->uuid) {
                $attachment->uuid = (string) Str::uuid();
            }

            $safeName = $this->safeFileName($attachment->name ?: 'cloud-document');
            $relativePath = sprintf(
                'mail-attachments/%s/%s_%s',
                $message->uuid,
                $attachment->uuid,
                $safeName
            );
            $absolutePath = Storage::disk($disk)->path($relativePath);

            $meta = $this->attachments->downloadSharedUrlToFile($url, $absolutePath);

            if (filled($meta['name'])) {
                $attachment->name = $meta['name'];
            }

            $attachment->forceFill([
                'path' => $relativePath,
                'sha256_hash' => $meta['sha256'],
                'size' => $meta['size'] ?: (int) $attachment->size,
                'content_type' => $meta['mime'] ?: $attachment->content_type,
                'download_status' => MailAttachment::STATUS_DOWNLOADED,
                'error_message' => null,
                'disk' => $disk,
            ])->save();

            return 'downloaded';
        } catch (GraphException $e) {
            // Access denied / not found → keep as openable link, not a hard failure.
            if (in_array($e->statusCode, [401, 403, 404], true) || $this->looksLikeAccessDenied($e->getMessage())) {
                $attachment->forceFill([
                    'download_status' => MailAttachment::STATUS_LINK_ONLY,
                    'error_message' => mb_substr('Link saved; Graph could not download file: '.$e->getMessage(), 0, 2000),
                    'path' => null,
                    'sha256_hash' => null,
                ])->save();

                return 'link_only';
            }

            Log::warning('mail_attachments.share_download_failed', [
                'mail_message_id' => $message->id,
                'url' => Str::limit($url, 250, ''),
                'error' => $e->getMessage(),
            ]);

            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return 'failed';
        } catch (Throwable $e) {
            Log::warning('mail_attachments.share_download_failed', [
                'mail_message_id' => $message->id,
                'url' => Str::limit($url, 250, ''),
                'error' => $e->getMessage(),
            ]);

            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return 'failed';
        }
    }

    /**
     * @return 'downloaded'|'failed'
     */
    private function downloadGraphFile(
        MailAttachment $attachment,
        MailMessage $message,
        string $graphAttachmentId,
        string $disk,
        int $size,
        ?string $contentType,
    ): string {
        try {
            if (! $attachment->uuid) {
                $attachment->uuid = (string) Str::uuid();
            }

            $safeName = $this->safeFileName($attachment->name);
            $relativePath = sprintf(
                'mail-attachments/%s/%s_%s',
                $message->uuid,
                $attachment->uuid,
                $safeName
            );

            $absolutePath = Storage::disk($disk)->path($relativePath);
            $meta = $this->attachments->downloadAttachmentToFile(
                (string) $message->graph_message_id,
                $graphAttachmentId,
                $absolutePath
            );

            $attachment->forceFill([
                'path' => $relativePath,
                'sha256_hash' => $meta['sha256'],
                'size' => $meta['size'] ?: $size,
                'content_type' => $contentType,
                'download_status' => MailAttachment::STATUS_DOWNLOADED,
                'error_message' => null,
                'source' => MailAttachment::SOURCE_GRAPH_FILE,
            ])->save();

            return 'downloaded';
        } catch (Throwable $e) {
            Log::warning('mail_attachments.download_failed', [
                'mail_message_id' => $message->id,
                'graph_attachment_id' => $graphAttachmentId,
                'error' => $e->getMessage(),
            ]);

            $attachment->forceFill([
                'download_status' => MailAttachment::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return 'failed';
        }
    }

    private function looksLikeAccessDenied(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'access denied')
            || str_contains($m, 'forbidden')
            || str_contains($m, 'do not have')
            || str_contains($m, 'not permitted');
    }

    private function safeFileName(string $name): string
    {
        $base = basename(str_replace(["\0", '\\', '/'], '', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'attachment';

        return Str::limit($base, 180, '');
    }

    /**
     * @param  array{downloaded: int, skipped: int, failed: int, link_only: int}  $counts
     */
    private function resolveMessageStatus(array $counts): string
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return 'none';
        }

        if ($counts['failed'] === 0 && ($counts['downloaded'] > 0 || $counts['link_only'] > 0 || $counts['skipped'] > 0)) {
            // All usable or intentionally skipped — treat as complete.
            if ($counts['downloaded'] > 0 || $counts['link_only'] > 0) {
                return 'downloaded';
            }

            return 'downloaded';
        }

        if ($counts['downloaded'] > 0 || $counts['link_only'] > 0 || $counts['skipped'] > 0) {
            return 'partial';
        }

        return 'failed';
    }
}
