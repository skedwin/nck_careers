<?php

namespace App\Services\MyJobs;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Position;
use App\Services\Applications\ApplicationProfileEnricher;
use App\Support\NairobiDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MyJobsImportService
{
    public function __construct(
        private readonly MyJobsXlsxReader $reader,
        private readonly MyJobsProfileExtractor $profiles,
        private readonly ApplicationProfileEnricher $enricher,
        private readonly MyJobsListingService $listing,
    ) {
    }

    /**
     * @return array{created: int, enriched: int, skipped: int, failed: int, dry_run: bool}
     */
    public function import(bool $overwrite = false, bool $dryRun = false): array
    {
        $created = 0;
        $enriched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->sourceRows() as $row) {
            try {
                $result = $this->importRow($row, $overwrite, $dryRun);
            } catch (\Throwable) {
                $failed++;
                continue;
            }

            match ($result) {
                'created' => $created++,
                'enriched' => $enriched++,
                default => $skipped++,
            };
        }

        if (! $dryRun) {
            $this->listing->forgetCache();
        }

        return [
            'created' => $created,
            'enriched' => $enriched,
            'skipped' => $skipped,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importRow(array $row, bool $overwrite, bool $dryRun): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        if ($name === '' && $email === '') {
            return 'skipped';
        }

        $positionId = isset($row['mapped_position_id']) ? (int) $row['mapped_position_id'] : 0;
        if ($positionId <= 0) {
            return 'skipped';
        }

        $key = $this->rowKey($email !== '' ? $email : $name, $positionId, (string) ($row['file'] ?? ''));
        $extracted = $this->profiles->extract($row);

        if ($dryRun) {
            $existing = $this->findExistingApplication($email, $name, $positionId, $key);

            return $existing ? 'enriched' : 'created';
        }

        return DB::transaction(function () use ($row, $name, $email, $positionId, $key, $extracted, $overwrite): string {
            $applicant = $this->findOrCreateApplicant($name, $email, $extracted, $overwrite);
            $existing = $this->findApplicationFor($applicant, $positionId, $key);

            if ($existing) {
                $this->applyProfile($existing, $applicant, $extracted, $row, $key, $overwrite, false);

                return 'enriched';
            }

            $application = Application::query()->create([
                'application_reference' => $this->generateReference(),
                'applicant_id' => $applicant->id,
                'position_id' => $positionId,
                'subject' => $this->subject($row),
                'status' => Application::STATUS_RECEIVED,
                'screening_status' => 'pending',
                'source' => 'myjobs',
                'received_at' => $extracted['received_at'] ?? now(),
                'notes' => $this->remark($row, $extracted),
                'nature_of_application' => 'one',
                'nature_of_application_detail' => 'Submitted via My Jobs In Kenya',
            ]);

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => null,
                'to_status' => Application::STATUS_RECEIVED,
                'user_id' => null,
                'note' => 'Created from MyJobs spreadsheet.',
                'created_at' => now(),
            ]);

            $this->applyProfile($application, $applicant, $extracted, $row, $key, true, true);

            return 'created';
        });
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function findOrCreateApplicant(string $name, string $email, array $extracted, bool $overwrite): Applicant
    {
        $applicant = null;
        if ($email !== '') {
            $applicant = Applicant::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }
        if (! $applicant && $name !== '' && $email === '') {
            $matches = Applicant::query()
                ->whereRaw('LOWER(full_name) = ?', [mb_strtolower($name)])
                ->get(['id']);
            if ($matches->count() === 1) {
                $applicant = Applicant::query()->find($matches->first()->id);
            }
        }

        if (! $applicant) {
            $applicant = Applicant::query()->create([
                'full_name' => $name !== '' ? $name : 'MyJobs applicant',
                'email' => $email !== '' ? $email : null,
                'phone' => $extracted['phone'] ?? null,
                'gender' => $extracted['gender'] ?? null,
                'meta' => ['source' => 'myjobs'],
            ]);
        }

        $this->enricher->applyToApplicant($applicant, $extracted, $overwrite);
        $meta = is_array($applicant->meta) ? $applicant->meta : [];
        $meta['source'] = $meta['source'] ?? 'myjobs';
        $meta['myjobs'] = array_filter([
            'age' => data_get($extracted, 'myjobs.age'),
            'age_years' => data_get($extracted, 'myjobs.age_years'),
            'company' => data_get($extracted, 'myjobs.company'),
            'current_position' => data_get($extracted, 'myjobs.current_position'),
        ]);
        $applicant->forceFill(['meta' => $meta])->save();

        return $applicant->refresh();
    }

    private function findExistingApplication(string $email, string $name, int $positionId, string $key): ?Application
    {
        $existing = Application::query()
            ->where('source', 'myjobs')
            ->where('position_id', $positionId)
            ->where('profile_extraction->myjobs->key', $key)
            ->first();
        if ($existing) {
            return $existing;
        }

        $applicant = null;
        if ($email !== '') {
            $applicant = Applicant::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }
        if (! $applicant) {
            return null;
        }

        return Application::query()
            ->where('applicant_id', $applicant->id)
            ->where('position_id', $positionId)
            ->first();
    }

    private function findApplicationFor(Applicant $applicant, int $positionId, string $key): ?Application
    {
        $byKey = Application::query()
            ->where('source', 'myjobs')
            ->where('position_id', $positionId)
            ->where('profile_extraction->myjobs->key', $key)
            ->first();
        if ($byKey) {
            return $byKey;
        }

        return Application::query()
            ->where('applicant_id', $applicant->id)
            ->where('position_id', $positionId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @param  array<string, mixed>  $row
     */
    private function applyProfile(
        Application $application,
        Applicant $applicant,
        array $extracted,
        array $row,
        string $key,
        bool $overwrite,
        bool $created,
    ): void {
        $this->enricher->applyToApplication($application, $extracted, $overwrite);

        $meta = is_array($application->profile_extraction) ? $application->profile_extraction : [];
        $myjobs = is_array($extracted['myjobs'] ?? null) ? $extracted['myjobs'] : [];
        $myjobs['key'] = $key;
        $myjobs['file'] = $row['file'] ?? ($myjobs['file'] ?? null);
        $meta['myjobs'] = $myjobs;
        $sources = $meta['sources'] ?? [];
        if (! in_array('myjobs_csv', $sources, true)) {
            $sources[] = 'myjobs_csv';
        }
        $meta['sources'] = $sources;

        $payload = [
            'profile_extraction' => $meta,
            'profile_extracted_at' => now(),
        ];
        if ($created || $overwrite || blank($application->nature_of_application)) {
            $payload['nature_of_application'] = 'one';
            $payload['nature_of_application_detail'] = 'Submitted via My Jobs In Kenya';
        }

        $remark = $this->remark($row, $extracted);
        if ($created || $overwrite || blank($application->notes)) {
            $payload['notes'] = $remark;
        } elseif (! str_contains((string) $application->notes, 'MyJobs')) {
            $payload['notes'] = trim($application->notes.' | '.$remark);
        }

        $application->forceFill($payload)->save();
        $this->enricher->applyToApplicant($applicant, $extracted, $overwrite);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $extracted
     */
    private function remark(array $row, array $extracted): string
    {
        $parts = ['MyJobs application'];
        $file = trim((string) ($row['file'] ?? ''));
        if ($file !== '') {
            $parts[] = pathinfo($file, PATHINFO_FILENAME);
        }
        $score = data_get($extracted, 'myjobs.score');
        if (filled($score)) {
            $parts[] = 'score '.$score;
        }
        $company = data_get($extracted, 'myjobs.company');
        $current = data_get($extracted, 'myjobs.current_position');
        if (filled($current) || filled($company)) {
            $parts[] = trim(($current ?: 'Current role').(filled($company) ? ' at '.$company : ''));
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function subject(array $row): string
    {
        $code = $row['mapped_position_code'] ?? null;
        $title = $row['mapped_position_title'] ?? $row['position'] ?? 'position';
        $name = $row['name'] ?? 'Applicant';

        return 'MyJobs application: '.$name.' — '.trim(($code ? $code.' ' : '').$title);
    }

    private function rowKey(string $identity, int $positionId, string $file): string
    {
        return sha1(strtolower(trim($identity)).'|'.$positionId.'|'.strtolower(trim($file)));
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function sourceRows(): \Generator
    {
        $dir = $this->listing->directory();
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        sort($files);
        $positions = Position::query()
            ->where('reference_code', 'like', 'NCK/REC%')
            ->get(['id', 'reference_code', 'title'])
            ->keyBy(fn (Position $position) => strtoupper((string) $position->reference_code));

        foreach ($files as $path) {
            $file = basename($path);
            if (str_starts_with($file, '~$')) {
                continue;
            }
            $mapped = $this->listing->mapFileToPositionPublic($file, $positions);
            foreach ($this->reader->rows($path) as $raw) {
                yield $raw + [
                    'file' => $file,
                    'mapped_position_id' => $mapped['id'],
                    'mapped_position_code' => $mapped['code'],
                    'mapped_position_title' => $mapped['title'],
                ];
            }
        }
    }

    private function generateReference(): string
    {
        $year = now()->timezone(NairobiDate::TZ)->format('Y');

        for ($i = 0; $i < 20; $i++) {
            $reference = sprintf('NCK-MJ-%s-%06d', $year, random_int(0, 999999));
            if (! Application::query()->where('application_reference', $reference)->exists()) {
                return $reference;
            }
        }

        return sprintf('NCK-MJ-%s-%s', $year, Str::upper(Str::random(6)));
    }
}
