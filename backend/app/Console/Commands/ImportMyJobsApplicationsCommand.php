<?php

namespace App\Console\Commands;

use App\Services\MyJobs\MyJobsImportService;
use Illuminate\Console\Command;

class ImportMyJobsApplicationsCommand extends Command
{
    protected $signature = 'myjobs:import-applications
        {--overwrite : Replace already filled profile fields from the spreadsheet}
        {--dry-run : Count creates/updates without writing}';

    protected $description = 'Create MyJobs applications and extract profiles from the MyJobs Excel/CSV files';

    public function handle(MyJobsImportService $importer): int
    {
        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry run: counting MyJobs applications that would be created or enriched…'
            : 'Importing MyJobs applications and extracting profiles from spreadsheet data…');

        $result = $importer->import($overwrite, $dryRun);

        $this->table(['Metric', 'Count'], [
            ['Created', $result['created']],
            ['Enriched existing', $result['enriched']],
            ['Skipped', $result['skipped']],
            ['Failed', $result['failed']],
        ]);

        if ($dryRun) {
            $this->comment('No records were written. Re-run without --dry-run to import.');
        }

        return self::SUCCESS;
    }
}
