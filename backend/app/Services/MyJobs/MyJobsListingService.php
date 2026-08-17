<?php

namespace App\Services\MyJobs;

use App\Models\Applicant;
use App\Models\Application;
use App\Support\NairobiDate;
use Illuminate\Support\Facades\Cache;

class MyJobsListingService
{
    public function __construct(private readonly MyJobsXlsxReader $reader)
    {
    }

    public function directory(): string
    {
        return storage_path('app/private/myjobs');
    }

    /**
     * @return array{
     *   generated_at: string|null,
     *   files: list<array<string, mixed>>,
     *   totals: array<string, int>,
     *   rows: list<array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function paginate(
        ?string $file = null,
        ?string $existence = null,
        ?string $search = null,
        int $perPage = 25,
        int $page = 1,
    ): array {
        $all = $this->matchedRows();
        $files = $this->fileSummaries($all);
        $filtered = $this->filterRows($all, $file, $existence, $search);

        $total = count($filtered);
        $perPage = max(1, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($filtered, $offset, $perPage);

        foreach ($slice as $i => $row) {
            $slice[$i]['serial_no'] = $offset + $i + 1;
        }

        $inSystem = count(array_filter($all, fn (array $row) => $row['in_system']));

        return [
            'generated_at' => NairobiDate::iso(now()),
            'files' => $files,
            'totals' => [
                'listed' => count($all),
                'in_system' => $inSystem,
                'missing' => count($all) - $inSystem,
                'by_email' => count(array_filter($all, fn (array $row) => $row['exists_by_email'])),
                'by_name' => count(array_filter($all, fn (array $row) => $row['exists_by_name'])),
                'by_name_only' => count(array_filter($all, fn (array $row) => $row['exists_by_name'] && ! $row['exists_by_email'])),
            ],
            'rows' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : $offset + count($slice),
                'file' => $file,
                'existence' => $existence,
                'search' => $search,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(?string $file = null, ?string $existence = null, ?string $search = null): array
    {
        $filtered = $this->filterRows($this->matchedRows(), $file, $existence, $search);
        foreach ($filtered as $i => $row) {
            $filtered[$i]['serial_no'] = $i + 1;
        }

        return $filtered;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterRows(array $rows, ?string $file, ?string $existence, ?string $search): array
    {
        return array_values(array_filter($rows, function (array $row) use ($file, $existence, $search): bool {
            if ($file && $row['file'] !== $file) {
                return false;
            }

            $mode = strtolower(trim((string) $existence));
            if (in_array($mode, ['in_system', 'exists', 'found'], true) && ! $row['in_system']) {
                return false;
            }
            if (in_array($mode, ['missing', 'not_in_system', 'new'], true) && $row['in_system']) {
                return false;
            }
            if (in_array($mode, ['email'], true) && ! $row['exists_by_email']) {
                return false;
            }
            if (in_array($mode, ['name'], true) && ! $row['exists_by_name']) {
                return false;
            }
            if (in_array($mode, ['name_only'], true) && (! $row['exists_by_name'] || $row['exists_by_email'])) {
                return false;
            }
            if (in_array($mode, ['email_only'], true) && (! $row['exists_by_email'] || $row['exists_by_name'])) {
                return false;
            }

            $q = strtolower(trim((string) $search));
            if ($q === '') {
                return true;
            }

            $hay = strtolower(implode(' ', [
                $row['name'] ?? '',
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['position'] ?? '',
                $row['file'] ?? '',
            ]));

            return str_contains($hay, $q);
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function matchedRows(): array
    {
        $dir = $this->directory();
        $stamp = $this->directoryStamp($dir);

        return Cache::remember('myjobs.listing.'.$stamp, 600, function () use ($dir): array {
            return $this->buildRows($dir);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fileSummaries(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $file = (string) $row['file'];
            if (! isset($map[$file])) {
                $map[$file] = [
                    'file' => $file,
                    'position_title' => $row['mapped_position_title'],
                    'position_code' => $row['mapped_position_code'],
                    'listed' => 0,
                    'in_system' => 0,
                    'missing' => 0,
                ];
            }
            $map[$file]['listed']++;
            if ($row['in_system']) {
                $map[$file]['in_system']++;
            } else {
                $map[$file]['missing']++;
            }
        }

        ksort($map);

        return array_values($map);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRows(string $dir): array
    {
        $index = $this->applicantIndex();
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        sort($files);

        $rows = [];
        $serial = 0;
        foreach ($files as $path) {
            $file = basename($path);
            if (str_starts_with($file, '~$')) {
                continue;
            }
            $mapped = $this->mapFileToPosition($file);
            foreach ($this->reader->rows($path) as $raw) {
                $name = trim((string) ($raw['name'] ?? ''));
                $email = strtolower(trim((string) ($raw['email'] ?? '')));
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = '';
                }

                $emailHits = $email !== '' ? ($index['by_email'][$email] ?? []) : [];
                $nameHits = $this->nameHits($name, $index);
                $merged = $this->mergeApplicants($emailHits, $nameHits);

                $existsByEmail = $emailHits !== [];
                $existsByName = $nameHits !== [];
                $match = 'none';
                if ($existsByEmail && $existsByName) {
                    $match = 'email_and_name';
                } elseif ($existsByEmail) {
                    $match = 'email';
                } elseif ($existsByName) {
                    $match = 'name';
                }

                $serial++;
                $rows[] = [
                    'serial_no' => $serial,
                    'file' => $file,
                    'name' => $name !== '' ? $name : null,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $this->nullable($raw['phone_no'] ?? $raw['phone'] ?? null),
                    'gender' => $this->nullable($raw['gender'] ?? null),
                    'education' => $this->nullable($raw['education'] ?? null),
                    'position' => $this->nullable($raw['position'] ?? null),
                    'applied_at' => $this->nullable($raw['application_date'] ?? null),
                    'score' => $this->nullable($raw['score'] ?? $raw['score_'] ?? null),
                    'mapped_position_id' => $mapped['id'],
                    'mapped_position_code' => $mapped['code'],
                    'mapped_position_title' => $mapped['title'],
                    'in_system' => $existsByEmail || $existsByName,
                    'exists_by_email' => $existsByEmail,
                    'exists_by_name' => $existsByName,
                    'match' => $match,
                    'matches' => $merged,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{
     *   by_email: array<string, list<array<string, mixed>>>,
     *   by_name: array<string, list<array<string, mixed>>>,
     *   by_tokens: array<string, list<array<string, mixed>>>,
     *   by_first_last: array<string, list<array<string, mixed>>>
     * }
     */
    private function applicantIndex(): array
    {
        $applicants = Applicant::query()
            ->with(['applications' => function ($query): void {
                $query->with('position:id,reference_code,title')
                    ->orderBy('received_at')
                    ->orderBy('id');
            }])
            ->get(['id', 'full_name', 'email', 'phone']);

        $byEmail = [];
        $byName = [];
        $byTokens = [];
        $byFirstLast = [];

        foreach ($applicants as $applicant) {
            $payload = $this->applicantPayload($applicant);
            $email = strtolower(trim((string) $applicant->email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $byEmail[$email][] = $payload;
            }

            $normalized = $this->normalizeName((string) $applicant->full_name);
            if ($normalized === '') {
                continue;
            }
            $byName[$normalized][] = $payload;

            $tokens = $this->nameTokens($normalized);
            if ($tokens !== []) {
                $tokenKey = $this->tokenKey($tokens);
                $byTokens[$tokenKey][] = $payload;
            }
            $firstLast = $this->firstLastKey($tokens);
            if ($firstLast !== null) {
                $byFirstLast[$firstLast][] = $payload;
            }
        }

        return [
            'by_email' => $byEmail,
            'by_name' => $byName,
            'by_tokens' => $byTokens,
            'by_first_last' => $byFirstLast,
        ];
    }

    /**
     * @param  array<string, mixed>  $index
     * @return list<array<string, mixed>>
     */
    private function nameHits(string $name, array $index): array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return [];
        }

        $hits = $index['by_name'][$normalized] ?? [];
        $tokens = $this->nameTokens($normalized);
        if ($tokens !== []) {
            $hits = array_merge($hits, $index['by_tokens'][$this->tokenKey($tokens)] ?? []);
        }
        $firstLast = $this->firstLastKey($tokens);
        if ($firstLast !== null) {
            $hits = array_merge($hits, $index['by_first_last'][$firstLast] ?? []);
        }

        return $this->uniqueApplicants($hits);
    }

    /**
     * @param  list<array<string, mixed>>  $emailHits
     * @param  list<array<string, mixed>>  $nameHits
     * @return list<array<string, mixed>>
     */
    private function mergeApplicants(array $emailHits, array $nameHits): array
    {
        $byId = [];
        foreach ($emailHits as $hit) {
            $id = (int) $hit['applicant_id'];
            $byId[$id] = $hit + ['matched_on' => 'email'];
        }
        foreach ($nameHits as $hit) {
            $id = (int) $hit['applicant_id'];
            if (isset($byId[$id])) {
                $byId[$id]['matched_on'] = 'email_and_name';
            } else {
                $byId[$id] = $hit + ['matched_on' => 'name'];
            }
        }

        return array_values($byId);
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private function uniqueApplicants(array $hits): array
    {
        $byId = [];
        foreach ($hits as $hit) {
            $byId[(int) $hit['applicant_id']] = $hit;
        }

        return array_values($byId);
    }

    private function applicantPayload(Applicant $applicant): array
    {
        $applications = $applicant->applications->map(function (Application $application): array {
            return [
                'application_id' => $application->id,
                'application_reference' => $application->application_reference,
                'position_code' => $application->position?->reference_code,
                'position_title' => $application->position?->title,
            ];
        })->values()->all();

        return [
            'applicant_id' => $applicant->id,
            'applicant_name' => $applicant->full_name,
            'applicant_email' => $applicant->email,
            'applications' => $applications,
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9\s]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $normalized): array
    {
        $stop = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'eng', 'sir', 'madam'];
        $parts = $normalized === '' ? [] : explode(' ', $normalized);
        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || in_array($part, $stop, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function tokenKey(array $tokens): string
    {
        $sorted = $tokens;
        sort($sorted);

        return implode('|', $sorted);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function firstLastKey(array $tokens): ?string
    {
        if (count($tokens) < 2) {
            return null;
        }

        return $tokens[0].'|'.$tokens[array_key_last($tokens)];
    }

    /**
     * @return array{id: int|null, code: string|null, title: string|null}
     */
    private function mapFileToPosition(string $file): array
    {
        $map = [
            'corporate communications officer.xlsx' => ['id' => 10, 'code' => 'NCK/REC7', 'title' => 'Corporate Communication Officer'],
            'corporation secretary & director legal services.xlsx' => ['id' => 5, 'code' => 'NCK/REC2', 'title' => 'Corporate Secretary & Director Legal Services'],
            'customer care assistant.xlsx' => ['id' => 14, 'code' => 'NCK/REC11', 'title' => 'Customer Care Assistant/Senior'],
            'deputy director human resources.xlsx' => ['id' => 8, 'code' => 'NCK/REC5', 'title' => 'Deputy Director, Human Resources and Administration'],
            'deputy director researchstrategy, planning &performance mgt.xlsx' => ['id' => 7, 'code' => 'NCK/REC4', 'title' => 'Deputy Director, Research, Strategy, Planning & Performance Management'],
            'director registration & licensing.xlsx' => ['id' => 4, 'code' => 'NCK/REC1', 'title' => 'Director Registration and Licensing'],
            'director corporate services.xlsx' => ['id' => 6, 'code' => 'NCK/REC3', 'title' => 'Director Corporate Services'],
            'education & examination officer.xlsx' => ['id' => 12, 'code' => 'NCK/REC9', 'title' => 'Education and Examination Officer'],
            'office administrators.xlsx' => ['id' => 15, 'code' => 'NCK/REC12', 'title' => 'Office Administrator'],
            'office assistants.xlsx' => ['id' => 16, 'code' => 'NCK/REC13', 'title' => 'Office Assistant'],
            'registration & licensing officer.xlsx' => ['id' => 11, 'code' => 'NCK/REC8', 'title' => 'Registration and Licensing Officer'],
            'senior corporate communications officer.xlsx' => ['id' => 9, 'code' => 'NCK/REC6', 'title' => 'Senior Corporate Communication Officer'],
            'senior customer care assistant.xlsx' => ['id' => 14, 'code' => 'NCK/REC11', 'title' => 'Customer Care Assistant/Senior'],
            'standards & compliance officer.xlsx' => ['id' => 13, 'code' => 'NCK/REC10', 'title' => 'Standards and Compliance Officer'],
        ];

        return $map[strtolower($file)] ?? ['id' => null, 'code' => null, 'title' => null];
    }

    private function directoryStamp(string $dir): string
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        $max = 0;
        $count = 0;
        foreach ($files as $file) {
            if (str_starts_with(basename($file), '~$')) {
                continue;
            }
            $count++;
            $max = max($max, (int) filemtime($file));
        }

        return (string) $max.'-'.$count;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }

        return $text;
    }
}
