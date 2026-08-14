<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'mailbox',
        'delta_link',
        'is_paused',
        'initial_sync_completed',
        'last_sync_run_id',
        'last_successful_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'is_paused' => 'boolean',
            'initial_sync_completed' => 'boolean',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    public function lastSyncRun(): BelongsTo
    {
        return $this->belongsTo(MailSyncRun::class, 'last_sync_run_id');
    }
}
