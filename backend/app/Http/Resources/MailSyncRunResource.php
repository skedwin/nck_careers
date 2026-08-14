<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MailSyncRun */
class MailSyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'mailbox' => $this->mailbox,
            'sync_type' => $this->sync_type,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'messages_discovered' => $this->messages_discovered,
            'messages_imported' => $this->messages_imported,
            'messages_skipped' => $this->messages_skipped,
            'messages_failed' => $this->messages_failed,
            'pages_processed' => $this->pages_processed,
            'error_summary' => $this->error_summary,
            'has_resume_cursor' => filled($this->next_link),
            'inbox_total_estimate' => data_get($this->meta, 'inbox_total_estimate'),
            'initiated_by' => $this->whenLoaded('initiator', fn () => [
                'id' => $this->initiator?->id,
                'name' => $this->initiator?->name,
                'email' => $this->initiator?->email,
            ]),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
