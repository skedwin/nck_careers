<?php

namespace App\Services\MicrosoftGraph;

/**
 * Lists and downloads file attachments from Microsoft Graph.
 */
class AttachmentService
{
    public function __construct(private readonly GraphClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function listAttachments(string $messageId): array
    {
        $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
        $messageId = rawurlencode($messageId);

        // Avoid $select so @odata.type and size/name are reliable across attachment subtypes.
        return $this->client->get("users/{$mailbox}/messages/{$messageId}/attachments");
    }

    /**
     * Fetch attachment metadata (no contentBytes — that field breaks on base attachment $select).
     *
     * @return array<string, mixed>
     */
    public function getAttachment(string $messageId, string $attachmentId): array
    {
        $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
        $messageId = rawurlencode($messageId);
        $attachmentId = rawurlencode($attachmentId);

        return $this->client->get(
            "users/{$mailbox}/messages/{$messageId}/attachments/{$attachmentId}"
        );
    }

    /**
     * Download raw attachment bytes via /$value (preferred over contentBytes).
     */
    public function downloadAttachmentBytes(string $messageId, string $attachmentId): string
    {
        $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
        $messageId = rawurlencode($messageId);
        $attachmentId = rawurlencode($attachmentId);

        return $this->client->getRaw(
            "users/{$mailbox}/messages/{$messageId}/attachments/{$attachmentId}/\$value"
        );
    }

    /**
     * Stream attachment /$value into a local file. Returns size + sha256 without loading all bytes into PHP.
     *
     * @return array{size: int, sha256: string}
     */
    public function downloadAttachmentToFile(string $messageId, string $attachmentId, string $destination): array
    {
        $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
        $messageId = rawurlencode($messageId);
        $attachmentId = rawurlencode($attachmentId);

        return $this->client->getRawToFile(
            "users/{$mailbox}/messages/{$messageId}/attachments/{$attachmentId}/\$value",
            $destination
        );
    }

    /**
     * Resolve a sharing URL via Graph and stream driveItem content to disk.
     *
     * @return array{size: int, sha256: string, name: ?string, mime: ?string}
     */
    public function downloadSharedUrlToFile(string $sharingUrl, string $destination): array
    {
        $shareId = $this->encodeSharingUrl($sharingUrl);
        $meta = $this->client->get("shares/{$shareId}/driveItem", [
            '$select' => 'id,name,size,file',
        ]);

        $fileMeta = $this->client->getRawToFile("shares/{$shareId}/driveItem/content", $destination);

        return [
            'size' => $fileMeta['size'],
            'sha256' => $fileMeta['sha256'],
            'name' => isset($meta['name']) ? (string) $meta['name'] : null,
            'mime' => isset($meta['file']['mimeType']) ? (string) $meta['file']['mimeType'] : null,
        ];
    }

    /**
     * Graph shares API encoding: u! + base64url(sharingUrl).
     */
    public function encodeSharingUrl(string $sharingUrl): string
    {
        $b64 = base64_encode($sharingUrl);
        $b64url = rtrim(strtr($b64, '+/', '-_'), '=');

        return 'u!'.$b64url;
    }

    /**
     * @deprecated Use downloadAttachmentBytes(); kept for mock/tests that still look for contentBytes.
     *
     * @return array<string, mixed>
     */
    public function downloadAttachmentContent(string $messageId, string $attachmentId): array
    {
        $meta = $this->getAttachment($messageId, $attachmentId);
        $bytes = $this->downloadAttachmentBytes($messageId, $attachmentId);
        $meta['contentBytes'] = base64_encode($bytes);

        return $meta;
    }
}
