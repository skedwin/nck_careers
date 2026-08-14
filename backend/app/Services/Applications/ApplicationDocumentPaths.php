<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\MailMessage;
use Illuminate\Support\Facades\Storage;

// Application + MailMessage provide local disk paths for text extraction.

class ApplicationDocumentPaths
{
    /**
     * @param  list<array{name: string, relative_path: string, absolute_path: string, mime: ?string, type: string}>  $files
     */
    public function __construct(public readonly array $files)
    {
    }

    public static function forMailMessage(MailMessage $message): self
    {
        $message->loadMissing('attachments');
        $files = [];

        foreach ($message->attachments as $attachment) {
            if (! $attachment->isDownloaded() || blank($attachment->path)) {
                continue;
            }

            $disk = $attachment->disk ?: 'private';
            $absolute = Storage::disk($disk)->path($attachment->path);
            $files[] = [
                'name' => (string) ($attachment->name ?: basename($attachment->path)),
                'relative_path' => (string) $attachment->path,
                'absolute_path' => $absolute,
                'mime' => $attachment->content_type,
                'type' => 'mail_attachment',
            ];
        }

        return new self($files);
    }

    public static function forApplication(Application $application): self
    {
        $application->loadMissing(['documents', 'mailMessage.attachments']);
        $files = [];
        $seen = [];

        foreach ($application->documents as $document) {
            if (blank($document->path)) {
                continue;
            }
            $disk = $document->disk ?: 'private';
            $key = $disk.'|'.$document->path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $files[] = [
                'name' => (string) ($document->original_name ?: basename($document->path)),
                'relative_path' => (string) $document->path,
                'absolute_path' => Storage::disk($disk)->path($document->path),
                'mime' => $document->mime_type,
                'type' => 'application_document',
            ];
        }

        if ($application->mailMessage) {
            foreach (self::forMailMessage($application->mailMessage)->files as $file) {
                $key = 'private|'.$file['relative_path'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $files[] = $file;
            }
        }

        return new self($files);
    }
}
