<?php

namespace App\Console\Commands;

use App\Services\Applications\ApplicationIngestionService;
use Illuminate\Console\Command;

class ConvertMailToApplicationsCommand extends Command
{
    protected $signature = 'mailbox:convert-applications {--limit=100 : Max messages to convert} {--position= : Optional position id}';

    protected $description = 'Convert imported mail messages into applications';

    public function handle(ApplicationIngestionService $ingestion): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $position = $this->option('position');
        $positionId = filled($position) ? (int) $position : null;

        $counts = $ingestion->convertPending($limit, $positionId);

        $this->info(sprintf(
            'Conversion complete: created=%d skipped=%d failed=%d',
            $counts['created'],
            $counts['skipped'],
            $counts['failed']
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
