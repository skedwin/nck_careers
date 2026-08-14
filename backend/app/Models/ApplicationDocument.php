<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApplicationDocument extends Model
{
    use HasFactory;

    public const TYPE_ATTACHMENT = 'attachment';

    public const TYPE_CLOUD_LINK = 'cloud_link';

    protected $fillable = [
        'uuid',
        'application_id',
        'mail_attachment_id',
        'document_type',
        'original_name',
        'disk',
        'path',
        'external_url',
        'mime_type',
        'size',
        'sha256_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (ApplicationDocument $document): void {
            if (empty($document->uuid)) {
                $document->uuid = (string) Str::uuid();
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function mailAttachment(): BelongsTo
    {
        return $this->belongsTo(MailAttachment::class);
    }

    public function isLocalFile(): bool
    {
        return filled($this->path);
    }

    public function isCloudLink(): bool
    {
        return filled($this->external_url) && ! $this->isLocalFile();
    }
}
