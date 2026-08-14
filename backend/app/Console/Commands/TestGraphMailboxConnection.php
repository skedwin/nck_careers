<?php

namespace App\Console\Commands;

use App\Services\MicrosoftGraph\MailboxConnectionService;
use Illuminate\Console\Command;

class TestGraphMailboxConnection extends Command
{
    protected $signature = 'graph:test-connection';

    protected $description = 'Test Microsoft Graph connectivity to the configured careers mailbox (read-only)';

    public function handle(MailboxConnectionService $service): int
    {
        $this->info('Testing Microsoft Graph mailbox connection...');
        $result = $service->testConnection();

        if ($result['success'] ?? false) {
            $this->info($result['message'] ?? 'Success');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Mode', $result['mode'] ?? ''],
                    ['Mailbox', $result['mailbox'] ?? ''],
                    ['Display name', data_get($result, 'mailbox_user.display_name')],
                    ['Inbox total', data_get($result, 'inbox.total_item_count')],
                    ['Sample pages', data_get($result, 'sample_messages.pages_retrieved')],
                    ['Duration (ms)', $result['duration_ms'] ?? ''],
                ]
            );

            return self::SUCCESS;
        }

        $this->error($result['message'] ?? 'Connection failed');
        if (! empty($result['error_code'])) {
            $this->line('Error code: '.$result['error_code']);
        }

        return self::FAILURE;
    }
}
