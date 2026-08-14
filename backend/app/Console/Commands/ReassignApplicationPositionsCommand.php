<?php

namespace App\Console\Commands;

use App\Services\Applications\ApplicationIngestionService;
use Illuminate\Console\Command;

class ReassignApplicationPositionsCommand extends Command
{
    protected $signature = 'applications:reassign-positions
        {--all : Also re-check applications that already have a position}
        {--limit=5000 : Max applications to process}';

    protected $description = 'Resolve position from email/application subject using fuzzy NCK/REC matching';

    public function handle(ApplicationIngestionService $ingestion): int
    {
        $result = $ingestion->reassignPositions(
            onlyUnassigned: ! $this->option('all'),
            limit: (int) $this->option('limit'),
        );

        $this->info(sprintf(
            'Positions reassigned: updated=%d unchanged=%d unmatched=%d cleared_invalid=%d',
            $result['updated'],
            $result['unchanged'],
            $result['unmatched'],
            $result['cleared_invalid'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
