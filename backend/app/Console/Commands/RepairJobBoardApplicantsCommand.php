<?php

namespace App\Console\Commands;

use App\Services\Applications\ApplicationIngestionService;
use Illuminate\Console\Command;

class RepairJobBoardApplicantsCommand extends Command
{
    protected $signature = 'applications:repair-job-board-applicants
        {--limit=500 : Max applications to repair}';

    protected $description = 'Split shared Careerjet/job-board applicants and set names from email subjects';

    public function handle(ApplicationIngestionService $ingestion): int
    {
        $result = $ingestion->repairJobBoardApplicants((int) $this->option('limit'));

        $this->table(
            ['Metric', 'Count'],
            [
                ['Split into new applicants', $result['split']],
                ['Renamed in place', $result['renamed']],
                ['Marked Received via Careerjet', $result['remarked']],
                ['Skipped', $result['skipped']],
            ]
        );

        return self::SUCCESS;
    }
}
