<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MailMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'graph_message_id',
        'internet_message_id',
        'conversation_id',
        'mailbox',
        'sender_name',
        'sender_email',
        'subject',
        'received_at',
        'body_text',
        'body_html',
        'has_attachments',
        'web_link',
        'to_recipients',
        'cc_recipients',
        'sync_status',
        'mail_sync_run_id',
        'attachments_status',
        'application_created',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'has_attachments' => 'boolean',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'application_created' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MailMessage $message): void {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MailSyncRun::class, 'mail_sync_run_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }
}
