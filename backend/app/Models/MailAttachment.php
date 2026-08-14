<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MailAttachment extends Model
{
    use HasFactory;

    public const SOURCE_GRAPH_FILE = 'graph_file';

    public const SOURCE_GRAPH_REFERENCE = 'graph_reference';

    public const SOURCE_BODY_LINK = 'body_link';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DOWNLOADED = 'downloaded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_LINK_ONLY = 'link_only';

    protected $fillable = [
        'uuid',
        'mail_message_id',
        'graph_attachment_id',
        'source',
        'provider',
        'external_url',
        'odata_type',
        'name',
        'content_type',
        'size',
        'is_inline',
        'sha256_hash',
        'disk',
        'path',
        'download_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MailAttachment $attachment): void {
            if (empty($attachment->uuid)) {
                $attachment->uuid = (string) Str::uuid();
            }
        });
    }

    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    public function applicationDocument(): HasOne
    {
        return $this->hasOne(ApplicationDocument::class);
    }

    public function isDownloaded(): bool
    {
        return $this->download_status === self::STATUS_DOWNLOADED && ! empty($this->path);
    }

    public function isLinkOnly(): bool
    {
        return $this->download_status === self::STATUS_LINK_ONLY && filled($this->external_url);
    }

    public function isUsableForApplication(): bool
    {
        return $this->isDownloaded() || $this->isLinkOnly();
    }
}
