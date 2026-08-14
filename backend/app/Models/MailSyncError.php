<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSyncError extends Model
{
    use HasFactory;

    protected $fillable = [
        'mail_sync_run_id',
        'graph_message_id',
        'stage',
        'error_message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MailSyncRun::class, 'mail_sync_run_id');
    }
}
