<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use Illuminate\Console\Command;

class ImportShortlistFromCsvCommand extends Command
{
    protected $signature = 'shortlist:import-csv
        {path? : CSV path (default: ../exports/shortlisted_applicants_contacts.csv)}
        {--dry-run : List matches without updating}';

    protected $description = 'Mark applications as shortlisted (and screening passed) from a CSV of application references';

    public function handle(): int
    {
        $path = $this->argument('path') ?? base_path('../exports/shortlisted_applicants_contacts.csv');
        $path = realpath($path) ?: $path;

        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");

            return self::FAILURE;
        }

        $refs = $this->refsFromCsv($path);
        if ($refs === []) {
            $this->warn('No application references found in CSV.');

            return self::FAILURE;
        }

        $applications = Application::query()
            ->whereIn('application_reference', $refs)
            ->get();

        $missing = array_values(array_diff($refs, $applications->pluck('application_reference')->all()));

        $this->info('References in CSV: '.count($refs));
        $this->info('Found in database: '.$applications->count());

        if ($missing !== []) {
            $this->warn('Not found: '.implode(', ', $missing));
        }

        if ($this->option('dry-run')) {
            foreach ($applications as $application) {
                $this->line("{$application->application_reference} · {$application->status} · screening {$application->screening_status}");
            }

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($applications as $application) {
            $fromStatus = $application->status;

            $application->forceFill([
                'status' => Application::STATUS_SHORTLISTED,
                'screening_status' => 'passed',
            ])->save();

            if ($fromStatus !== Application::STATUS_SHORTLISTED) {
                ApplicationStatusHistory::query()->create([
                    'application_id' => $application->id,
                    'from_status' => $fromStatus,
                    'to_status' => Application::STATUS_SHORTLISTED,
                    'user_id' => null,
                    'note' => 'Marked shortlisted from CSV import.',
                    'created_at' => now(),
                ]);
            }

            $updated++;
            $this->line("Shortlisted {$application->application_reference}");
        }

        $this->info("Updated {$updated} application(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function refsFromCsv(string $path): array
    {
        $refs = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        fgetcsv($handle); // header
        while (($row = fgetcsv($handle)) !== false) {
            $ref = trim($row[1] ?? '', '"');
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        fclose($handle);

        return array_values(array_unique($refs));
    }
}
