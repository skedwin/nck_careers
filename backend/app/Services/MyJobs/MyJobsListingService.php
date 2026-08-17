<?php

namespace App\Services\MyJobs;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Position;
use App\Support\NairobiDate;
use Illuminate\Support\Collection;
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
        $alsoInMailbox = count(array_filter($all, fn (array $row) => ! empty($row['also_in_mailbox'])));

        return [
            'generated_at' => NairobiDate::iso(now()),
            'files' => $files,
            'totals' => [
                'listed' => count($all),
                'in_system' => $inSystem,
                'missing' => count($all) - $inSystem,
                'also_in_mailbox' => $alsoInMailbox,
                'myjobs_only' => count($all) - $alsoInMailbox,
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
            if (in_array($mode, ['both', 'also_in_mailbox', 'duplicates'], true) && empty($row['also_in_mailbox'])) {
                return false;
            }
            if (in_array($mode, ['myjobs_only', 'portal_only'], true) && ! empty($row['also_in_mailbox'])) {
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

        return Cache::remember($this->listingCacheKey(), 600, function () use ($dir): array {
            return $this->buildRows($dir);
        });
    }

    public function forgetCache(): void
    {
        $current = (int) Cache::get('myjobs.listing.version', 1);
        Cache::forever('myjobs.listing.version', $current + 1);
    }

    private function listingCacheKey(): string
    {
        $version = max(1, (int) Cache::get('myjobs.listing.version', 1));

        return 'myjobs.listing.v'.$version.'.'.$this->directoryStamp($this->directory());
    }

    public function normalizeNamePublic(string $name): string
    {
        return $this->normalizeName($name);
    }

    public function namesMatch(string $left, string $right): bool
    {
        $a = $this->normalizeName($left);
        $b = $this->normalizeName($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $aTokens = $this->nameTokens($a);
        $bTokens = $this->nameTokens($b);
        if ($aTokens === [] || $bTokens === []) {
            return false;
        }
        if ($this->tokenKey($aTokens) === $this->tokenKey($bTokens)) {
            return true;
        }

        $aFirstLast = $this->firstLastKey($aTokens);
        $bFirstLast = $this->firstLastKey($bTokens);
        if ($aFirstLast && $aFirstLast === $bFirstLast) {
            return true;
        }

        $shorter = count($aTokens) <= count($bTokens) ? $aTokens : $bTokens;
        $longer = count($aTokens) <= count($bTokens) ? $bTokens : $aTokens;
        if (count($shorter) < 2) {
            return false;
        }

        foreach ($shorter as $token) {
            if (! in_array($token, $longer, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<string, Position>|null  $positions
     * @return array{id: int|null, code: string|null, title: string|null}
     */
    public function mapFileToPositionPublic(string $file, ?Collection $positions = null): array
    {
        return $this->mapFileToPosition($file, $positions);
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
        $positions = Position::query()
            ->where('reference_code', 'like', 'NCK/REC%')
            ->get(['id', 'reference_code', 'title'])
            ->keyBy(fn (Position $position) => strtoupper((string) $position->reference_code));

        $rows = [];
        $serial = 0;
        foreach ($files as $path) {
            $file = basename($path);
            if (str_starts_with($file, '~$')) {
                continue;
            }
            $mapped = $this->mapFileToPosition($file, $positions);
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

                $channel = $this->channelFromMatches($merged, $mapped['id']);

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
                    'company' => $this->nullable($raw['company'] ?? null),
                    'age' => $this->nullable($raw['age'] ?? null),
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
                    'also_in_mailbox' => $channel['also_in_mailbox'],
                    'channel' => $channel['also_in_mailbox'] ? 'both' : 'myjobs_only',
                    'mailbox_applications' => $channel['mailbox_applications'],
                    'myjobs_applications' => $channel['myjobs_applications'],
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

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array{
     *   also_in_mailbox: bool,
     *   mailbox_applications: list<array<string, mixed>>,
     *   myjobs_applications: list<array<string, mixed>>
     * }
     */
    public function channelFromMatches(array $matches, ?int $positionId): array
    {
        $mailbox = [];
        $myjobs = [];

        foreach ($matches as $match) {
            foreach ($match['applications'] ?? [] as $application) {
                if ($positionId && (int) ($application['position_id'] ?? 0) !== $positionId) {
                    continue;
                }
                $row = $application + [
                    'applicant_id' => $match['applicant_id'] ?? null,
                    'applicant_name' => $match['applicant_name'] ?? null,
                    'applicant_email' => $match['applicant_email'] ?? null,
                    'matched_on' => $match['matched_on'] ?? null,
                ];
                if ($this->isMailboxSource($application['source'] ?? null)) {
                    $mailbox[(int) $application['application_id']] = $row;
                } else {
                    $myjobs[(int) $application['application_id']] = $row;
                }
            }
        }

        return [
            'also_in_mailbox' => $mailbox !== [],
            'mailbox_applications' => array_values($mailbox),
            'myjobs_applications' => array_values($myjobs),
        ];
    }

    public function isMailboxSource(mixed $source): bool
    {
        return strcasecmp(trim((string) $source), 'myjobs') !== 0;
    }

    private function applicantPayload(Applicant $applicant): array
    {
        $applications = $applicant->applications->map(function (Application $application): array {
            return [
                'application_id' => $application->id,
                'application_reference' => $application->application_reference,
                'position_id' => $application->position_id,
                'position_code' => $application->position?->reference_code,
                'position_title' => $application->position?->title,
                'source' => $application->source,
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
     * @param  Collection<string, Position>|null  $positions
     * @return array{id: int|null, code: string|null, title: string|null}
     */
    private function mapFileToPosition(string $file, ?Collection $positions = null): array
    {
        $map = [
            'corporate communications officer.xlsx' => 'NCK/REC7',
            'corporation secretary & director legal services.xlsx' => 'NCK/REC2',
            'customer care assistant.xlsx' => 'NCK/REC11',
            'deputy director human resources.xlsx' => 'NCK/REC5',
            'deputy director researchstrategy, planning &performance mgt.xlsx' => 'NCK/REC4',
            'director registration & licensing.xlsx' => 'NCK/REC1',
            'director corporate services.xlsx' => 'NCK/REC3',
            'education & examination officer.xlsx' => 'NCK/REC9',
            'office administrators.xlsx' => 'NCK/REC12',
            'office assistants.xlsx' => 'NCK/REC13',
            'registration & licensing officer.xlsx' => 'NCK/REC8',
            'senior corporate communications officer.xlsx' => 'NCK/REC6',
            'senior customer care assistant.xlsx' => 'NCK/REC11',
            'standards & compliance officer.xlsx' => 'NCK/REC10',
        ];

        $code = $map[strtolower($file)] ?? null;
        if ($code === null) {
            return ['id' => null, 'code' => null, 'title' => null];
        }

        $positions ??= Position::query()
            ->where('reference_code', 'like', 'NCK/REC%')
            ->get(['id', 'reference_code', 'title'])
            ->keyBy(fn (Position $position) => strtoupper((string) $position->reference_code));

        $position = $positions->get($code);

        return [
            'id' => $position?->id,
            'code' => $code,
            'title' => $position?->title,
        ];
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
