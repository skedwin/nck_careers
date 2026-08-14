<?php

namespace App\Console\Commands;

use App\Services\MicrosoftGraph\Exceptions\GraphException;
use App\Services\MicrosoftGraph\SyncService;
use Illuminate\Console\Command;

class SyncMailboxCommand extends Command
{
    protected $signature = 'mailbox:sync {--type= : Force sync type: initial|incremental|manual}';

    protected $description = 'Queue a mailbox synchronization run for careers@nckenya.go.ke';

    public function handle(SyncService $syncService): int
    {
        try {
            $run = $syncService->startSync(
                trigger: 'manual',
                forcedType: $this->option('type') ?: null,
            );
        } catch (GraphException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued sync run #{$run->id} ({$run->sync_type}).");
        $this->line('Process queues with: php artisan queue:work --queue=mail-sync,mail-import,default');

        return self::SUCCESS;
    }
}
