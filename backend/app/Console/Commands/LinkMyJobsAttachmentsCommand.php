<?php

namespace App\Console\Commands;

use App\Services\MyJobs\MyJobsAttachmentLinker;
use Illuminate\Console\Command;

class LinkMyJobsAttachmentsCommand extends Command
{
    protected $signature = 'myjobs:link-attachments
        {--dry-run : Match files without writing document records or extracting zips}';

    protected $description = 'Link MyJobs application documents from storage/app/private/myjobs_files by job folder and applicant file names';

    public function handle(MyJobsAttachmentLinker $linker): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun
            ? 'Dry run: matching MyJobs attachment packs without writing…'
            : 'Extracting MyJobs zips and linking documents to applications…');

        $result = $linker->link($dryRun);

        $this->table(['Metric', 'Count'], [
            ['Zips to extract', $result['zips_extracted']],
            ['Applicant packs', $result['packs']],
            ['Linked applications', $result['linked']],
            ['Documents', $result['documents']],
            ['Unmatched', $result['unmatched']],
            ['Ambiguous', $result['ambiguous']],
            ['Skipped', $result['skipped']],
        ]);

        if ($result['unmatched_samples'] !== []) {
            $this->comment('Unmatched samples:');
            foreach ($result['unmatched_samples'] as $sample) {
                $this->line('  · '.$sample);
            }
        }

        if ($dryRun) {
            $this->comment('No records were written. Re-run without --dry-run to link files.');
        }

        return self::SUCCESS;
    }
}
