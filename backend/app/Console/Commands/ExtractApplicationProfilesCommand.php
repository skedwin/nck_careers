<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\MailMessage;
use App\Services\Applications\ApplicationProfileEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class ExtractApplicationProfilesCommand extends Command
{
    protected $signature = 'applications:extract-profiles
        {--limit=200 : Max applications to process}
        {--after-id=0 : Resume after this application id (exclusive)}
        {--id= : Process a single application id}
        {--position= : Position id or reference code (e.g. NCK/REC5)}
        {--missing-only : Only rows with empty profile_extracted_at}
        {--needs-doc-scan : Only apps that have local docs but have not scanned documents yet}
        {--overwrite : Replace already filled profile fields}
        {--isolate : Run each application in a child process (survives PDF OOM)}
        {--no-isolate : Disable child-process isolation}';

    protected $description = 'Extract phone, ID, qualifications, courses, membership, experience and skills from email body + CV/PDF documents';

    public function handle(ApplicationProfileEnricher $enricher): int
    {
        @ini_set('memory_limit', '1024M');

        $singleId = $this->option('id');
        if (filled($singleId)) {
            return $this->processOne((int) $singleId, $enricher, (bool) $this->option('overwrite'));
        }

        $limit = max(1, (int) $this->option('limit'));
        $afterId = max(0, (int) $this->option('after-id'));
        $missingOnly = (bool) $this->option('missing-only');
        $needsDocScan = (bool) $this->option('needs-doc-scan');
        $overwrite = (bool) $this->option('overwrite');
        // Isolation is on by default for batch runs.
        $isolate = ! ((bool) $this->option('no-isolate'));

        $query = Application::query()
            ->whereNotNull('mail_message_id')
            ->orderBy('id');

        $positionOpt = trim((string) $this->option('position'));
        if ($positionOpt !== '') {
            $positionId = $this->resolvePositionId($positionOpt);
            if (! $positionId) {
                $this->error("Position [{$positionOpt}] not found.");

                return self::FAILURE;
            }
            $query->where('position_id', $positionId);
            $this->info("Filtering position_id={$positionId}");
        }

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        if ($missingOnly) {
            $query->whereNull('profile_extracted_at');
        }

        if ($needsDocScan) {
            $query->whereHas('documents', function ($q): void {
                $q->whereNotNull('path')->where('path', '!=', '');
            })->where(function ($q): void {
                $q->whereNull('profile_extraction')
                    ->orWhereRaw("JSON_EXTRACT(profile_extraction, '$.documents_scanned') IS NULL")
                    ->orWhereRaw("CAST(JSON_EXTRACT(profile_extraction, '$.documents_scanned') AS UNSIGNED) = 0");
            });
        }

        $ids = $query->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('No applications to process.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Extracting profiles for %d application(s)%s%s…',
            $ids->count(),
            $afterId > 0 ? " after id={$afterId}" : '',
            $isolate ? ' [isolated]' : ''
        ));

        $updated = 0;
        $errors = 0;
        $index = 0;
        $lastId = $afterId;

        foreach ($ids as $id) {
            $index++;
            $lastId = (int) $id;

            if ($isolate) {
                $result = $this->runIsolated((int) $id, $overwrite);
                if ($result['ok']) {
                    $updated++;
                    $this->line(sprintf('[%d/%d] id=%d OK %s', $index, $ids->count(), $id, $result['line']));
                } else {
                    $errors++;
                    $this->warn(sprintf('[%d/%d] id=%d FAIL %s', $index, $ids->count(), $id, $result['line']));
                    $this->markPartialFailure((int) $id, $result['line']);
                }
            } else {
                $code = $this->processOne((int) $id, $enricher, $overwrite);
                if ($code === self::SUCCESS) {
                    $updated++;
                } else {
                    $errors++;
                    $this->markPartialFailure((int) $id, 'in-process failure');
                }
            }

            gc_collect_cycles();
        }

        $remaining = Application::query()
            ->whereNotNull('mail_message_id')
            ->whereNull('profile_extracted_at')
            ->count();

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Updated', $updated],
            ['Errors', $errors],
            ['Last application id', $lastId],
            ['Still missing profile_extracted_at', $remaining],
        ]);
        $this->comment("Resume with: php artisan applications:extract-profiles --after-id={$lastId} --limit=300 --overwrite");

        return self::SUCCESS;
    }

    private function processOne(int $id, ApplicationProfileEnricher $enricher, bool $overwrite): int
    {
        $application = Application::query()
            ->with(['mailMessage', 'applicant', 'documents', 'mailMessage.attachments'])
            ->find($id);

        if (! $application) {
            $this->warn("Application {$id} not found.");

            return self::FAILURE;
        }

        if (! $application->mailMessage instanceof MailMessage) {
            $this->warn("Application {$id} has no mail message.");

            return self::FAILURE;
        }

        try {
            $extracted = $enricher->enrichFromApplication($application, $overwrite);
            $filled = collect($extracted)
                ->except(['evidence', 'document_sources', 'documents_scanned', 'documents_skipped'])
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->count();
            $docs = (int) ($extracted['documents_scanned'] ?? 0);
            $skippedDocs = count($extracted['documents_skipped'] ?? []);
            $this->line(sprintf(
                '%s docs=%d skipped_docs=%d fields=%d phone=%s id=%s qual=%s',
                $application->application_reference,
                $docs,
                $skippedDocs,
                $filled,
                $extracted['phone'] ?? '-',
                $extracted['national_id'] ?? '-',
                $extracted['highest_qualification'] ?? '-'
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('ERROR: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{ok: bool, line: string}
     */
    private function runIsolated(int $id, bool $overwrite): array
    {
        $command = [
            PHP_BINARY,
            'artisan',
            'applications:extract-profiles',
            '--id='.$id,
            '--no-isolate',
        ];
        if ($overwrite) {
            $command[] = '--overwrite';
        }

        $process = new Process($command, base_path(), null, null, 180);
        $process->run();

        $output = trim($process->getOutput().' '.$process->getErrorOutput());
        $output = preg_replace('/\s+/', ' ', $output) ?? $output;

        if ($process->isSuccessful()) {
            return ['ok' => true, 'line' => Str::limit($output, 180, '')];
        }

        $hint = $output !== '' ? $output : ('exit '.$process->getExitCode());
        if (str_contains(strtolower($hint), 'memory size') || (int) $process->getExitCode() === 255) {
            $hint = 'OOM/crash while parsing PDF — skipped';
        }

        return ['ok' => false, 'line' => Str::limit($hint, 180, '')];
    }

    private function markPartialFailure(int $id, string $reason): void
    {
        $application = Application::query()->find($id);
        if (! $application) {
            return;
        }

        $meta = is_array($application->profile_extraction) ? $application->profile_extraction : [];
        $meta['last_error'] = Str::limit($reason, 500, '');
        $application->forceFill([
            'profile_extraction' => $meta,
            'profile_extracted_at' => $application->profile_extracted_at ?? now(),
        ])->save();
    }

    private function resolvePositionId(string $positionOpt): ?int
    {
        if (ctype_digit($positionOpt)) {
            return (int) $positionOpt;
        }

        $code = strtoupper(trim($positionOpt));

        return \App\Models\Position::query()
            ->where('reference_code', $code)
            ->value('id');
    }
}
