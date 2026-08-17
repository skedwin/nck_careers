<?php

namespace App\Services\Reports;

use App\Models\Application;
use App\Models\Position;
use App\Services\Access\PositionScopeService;
use App\Support\NairobiDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LongListingReportService
{
    public const SOURCE_MAILBOX = 'mailbox';

    public const SOURCE_MYJOBS = 'myjobs';

    private string $listingSource = self::SOURCE_MAILBOX;

    public function __construct(private readonly PositionScopeService $positionScope)
    {
    }

    public function usingSource(?string $source): self
    {
        $this->listingSource = $source === self::SOURCE_MYJOBS
            ? self::SOURCE_MYJOBS
            : self::SOURCE_MAILBOX;

        return $this;
    }

    public function listingSource(): string
    {
        return $this->listingSource;
    }

    private function constrainSource(Builder $query, bool $joined = false): void
    {
        $column = $joined ? 'applications.source' : 'source';
        if ($this->listingSource === self::SOURCE_MYJOBS) {
            $query->myJobs($column);

            return;
        }

        $query->notMyJobs($column);
    }

    /**
     * Lightweight category index for the Reports landing list.
     *
     * @return array{generated_at: string|null, categories: list<array<string, mixed>>, unassigned: array<string, mixed>}
     */
    public function categoryIndex(bool $includeUnassigned = true): array
    {
        $positions = $this->positionScope->scopePositionsQuery(
            Position::query()
                ->where('reference_code', 'like', 'NCK/REC%')
                ->orderBy('sort_order')
                ->orderBy('id')
        )->get(['id', 'uuid', 'reference_code', 'title', 'department', 'vacancies', 'sort_order']);

        $counts = Application::query()
            ->whereNull('duplicate_hidden_at');
        $this->constrainSource($counts);
        $counts = $counts
            ->selectRaw('position_id, COUNT(*) as total')
            ->groupBy('position_id')
            ->pluck('total', 'position_id');

        $withDocuments = Application::query()
            ->whereNull('duplicate_hidden_at')
            ->whereHas('documents');
        $this->constrainSource($withDocuments);
        $withDocuments = $withDocuments
            ->selectRaw('position_id, COUNT(*) as total')
            ->groupBy('position_id')
            ->pluck('total', 'position_id');

        $categories = $positions->map(function (Position $position) use ($counts, $withDocuments) {
            $duplicateCount = count($this->duplicateGroupsForPosition((int) $position->id)['duplicate_application_ids']);
            $total = (int) ($counts[$position->id] ?? 0);
            $docs = (int) ($withDocuments[$position->id] ?? 0);

            return [
                'key' => (string) $position->id,
                'position_id' => $position->id,
                'reference_code' => $position->reference_code,
                'title' => $position->title,
                'department' => $position->department,
                'vacancies' => $position->vacancies,
                'total_applicants' => $total,
                'duplicate_applicants' => $duplicateCount,
                'with_documents' => $docs,
                'without_documents' => max(0, $total - $docs),
            ];
        })->values()->all();

        $unassignedCount = Application::query()
            ->whereNull('position_id')
            ->whereNull('duplicate_hidden_at');
        $this->constrainSource($unassignedCount);
        $unassignedCount = $unassignedCount->count();
        $unassignedDuplicates = count($this->duplicateGroupsForPosition(null)['duplicate_application_ids']);
        $unassignedWithDocs = Application::query()
            ->whereNull('position_id')
            ->whereNull('duplicate_hidden_at')
            ->whereHas('documents');
        $this->constrainSource($unassignedWithDocs);
        $unassignedWithDocs = $unassignedWithDocs->count();

        $unassigned = [
            'key' => 'unassigned',
            'position_id' => null,
            'reference_code' => null,
            'title' => 'Unassigned / category not identified',
            'department' => null,
            'vacancies' => null,
            'total_applicants' => (int) $unassignedCount,
            'duplicate_applicants' => $unassignedDuplicates,
            'with_documents' => (int) $unassignedWithDocs,
            'without_documents' => max(0, (int) $unassignedCount - (int) $unassignedWithDocs),
        ];

        return [
            'generated_at' => NairobiDate::iso(now()),
            'source' => $this->listingSource,
            'categories' => $categories,
            'unassigned' => $includeUnassigned && ! $this->positionScope->isRestricted() ? $unassigned : null,
        ];
    }

    /**
     * Paginated long listing for one category (position id or unassigned).
     *
     * @return array{category: array<string, mixed>, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginateCategory(
        string $categoryKey,
        ?string $search = null,
        int $perPage = 25,
        int $page = 1,
        ?string $qualification = null,
        ?string $duplicates = null,
        ?string $match = null,
        ?string $documents = null,
    ): array {
        $this->positionScope->assertCanAccessCategoryKey($categoryKey);
        $category = $this->resolveCategoryMeta($categoryKey);
        $positionId = $category['position_id'];
        $duplicateMeta = $this->duplicateGroupsForPosition($positionId);

        $query = $this->baseQuery($positionId);
        $this->applySearch($query, $search);
        $this->applyQualificationFilter($query, $qualification);
        $this->applyDuplicatesFilter($query, $duplicates, $duplicateMeta, $match);
        $this->applyDocumentsFilter($query, $documents);

        /** @var LengthAwarePaginator<int, Application> $paginator */
        $paginator = $query
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();

        $rows = collect($paginator->items())
            ->values()
            ->map(function (Application $app, int $index) use ($offset, $duplicateMeta) {
                $row = $this->row($app, $offset + $index + 1);

                return $this->withDuplicateFields($row, $app->id, $duplicateMeta);
            })
            ->all();

        return [
            'category' => $category + [
                'total_applicants' => $paginator->total(),
                'duplicate_applicants' => count($duplicateMeta['duplicate_application_ids']),
            ],
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'search' => $search,
                'qualification' => $qualification,
                'duplicates' => $duplicates,
                'match' => $match,
                'source' => $this->listingSource,
            ],
            'generated_at' => NairobiDate::iso(now()),
        ];
    }

    /**
     * Full listing (legacy / CSV support).
     *
     * @return array{generated_at: string|null, categories: list<array<string, mixed>>, unassigned: array<string, mixed>|null}
     */
    public function build(?int $positionId = null, bool $includeUnassigned = true): array
    {
        if ($positionId !== null) {
            $this->positionScope->assertCanAccessPosition($positionId);
        }

        $positionsQuery = $this->positionScope->scopePositionsQuery(
            Position::query()
                ->where('reference_code', 'like', 'NCK/REC%')
                ->orderBy('sort_order')
                ->orderBy('id')
        );

        if ($positionId) {
            $positionsQuery->whereKey($positionId);
        }

        $positions = $positionsQuery->get();

        $categories = [];
        foreach ($positions as $position) {
            $applications = $this->applicationsForPosition((int) $position->id);
            $categories[] = $this->categoryPayload($position, $applications);
        }

        $unassigned = null;
        if ($includeUnassigned && ! $positionId && ! $this->positionScope->isRestricted()) {
            $apps = $this->applicationsForPosition(null);
            $unassigned = [
                'position_id' => null,
                'reference_code' => null,
                'title' => 'Unassigned / category not identified',
                'vacancies' => null,
                'total_applicants' => $apps->count(),
                'rows' => $apps->values()->map(fn (Application $app, int $index) => $this->row($app, $index + 1))->all(),
            ];
        }

        return [
            'generated_at' => NairobiDate::iso(now()),
            'categories' => $categories,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function csvRows(?int $positionId = null, bool $includeUnassigned = true, bool $unassignedOnly = false): array
    {
        $includeCategory = $positionId === null && ! $unassignedOnly;

        if ($unassignedOnly) {
            $this->positionScope->assertCanAccessCategoryKey('unassigned');
            $apps = $this->applicationsForPosition(null);
            $out = [];
            foreach ($apps->values() as $index => $app) {
                $out[] = $this->csvMap('UNASSIGNED', 'Unassigned / category not identified', $this->row($app, $index + 1), $includeCategory);
            }

            return $out;
        }

        if ($positionId !== null) {
            $this->positionScope->assertCanAccessPosition($positionId);
        }

        $report = $this->build(
            $positionId,
            $includeUnassigned && $positionId === null && ! $this->positionScope->isRestricted(),
        );
        $out = [];

        foreach ($report['categories'] as $category) {
            foreach ($category['rows'] as $row) {
                $out[] = $this->csvMap($category['reference_code'], $category['title'], $row, $includeCategory || $positionId === null);
            }
        }

        if ($report['unassigned'] && ($report['unassigned']['total_applicants'] ?? 0) > 0) {
            foreach ($report['unassigned']['rows'] as $row) {
                $out[] = $this->csvMap('UNASSIGNED', $report['unassigned']['title'], $row, true);
            }
        }

        return $out;
    }

    /**
     * CSV for one category key (id or "unassigned"), optionally filtered by search / qualification.
     *
     * @return list<array<string, scalar|null>>
     */
    public function csvRowsForCategory(
        string $categoryKey,
        ?string $search = null,
        ?string $qualification = null,
        ?string $duplicates = null,
        ?string $match = null,
        ?string $documents = null,
    ): array {
        $this->positionScope->assertCanAccessCategoryKey($categoryKey);
        $category = $this->resolveCategoryMeta($categoryKey);
        $duplicateMeta = $this->duplicateGroupsForPosition($category['position_id']);
        $query = $this->baseQuery($category['position_id']);
        $this->applySearch($query, $search);
        $this->applyQualificationFilter($query, $qualification);
        $this->applyDuplicatesFilter($query, $duplicates, $duplicateMeta, $match);
        $this->applyDocumentsFilter($query, $documents);
        $apps = $query->orderBy('received_at')->orderBy('id')->get();

        $out = [];
        foreach ($apps->values() as $index => $app) {
            $row = $this->withDuplicateFields($this->row($app, $index + 1), $app->id, $duplicateMeta);
            $out[] = $this->csvMap(
                $category['reference_code'] ?? 'UNASSIGNED',
                $category['title'],
                $row,
                false
            );
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function csvHeaders(bool $includeCategory = false): array
    {
        $headers = [
            'SN.',
            'Unique Identifier',
            'Applicant Name',
            'Telephone/Mobile No',
            'Email',
            'ID No',
            'PWD(Yes/No)',
            'County of Origin',
            'Gender',
            'Status of the application received as one or in pieces(Cover letter, CV & Certificates) Yes/No',
            'Academic Qualifications',
            'Professional Membership',
            'Proficiency in Computer Studies',
            'Years of Working Experience',
            'Comments/Remarks-- for PWD must indicated or attach in the certificates',
            'Documents',
        ];

        if ($includeCategory) {
            array_unshift($headers, 'Category Code', 'Category / Position');
        }

        return $headers;
    }

    /**
     * @return array{key: string, position_id: int|null, reference_code: string|null, title: string, department: mixed, vacancies: mixed}
     */
    private function resolveCategoryMeta(string $categoryKey): array
    {
        if ($categoryKey === 'unassigned') {
            return [
                'key' => 'unassigned',
                'position_id' => null,
                'reference_code' => null,
                'title' => 'Unassigned / category not identified',
                'department' => null,
                'vacancies' => null,
            ];
        }

        $position = Position::query()
            ->where('reference_code', 'like', 'NCK/REC%')
            ->where(function (Builder $q) use ($categoryKey): void {
                if (ctype_digit($categoryKey)) {
                    $q->whereKey((int) $categoryKey);
                } else {
                    $q->where('reference_code', strtoupper($categoryKey));
                }
            })
            ->firstOrFail();

        return [
            'key' => (string) $position->id,
            'position_id' => $position->id,
            'reference_code' => $position->reference_code,
            'title' => $position->title,
            'department' => $position->department,
            'vacancies' => $position->vacancies,
        ];
    }

    private function baseQuery(?int $positionId): Builder
    {
        $query = Application::query()
            ->with(['applicant', 'documents'])
            ->whereNull('duplicate_hidden_at')
            ->when(
                $positionId === null,
                fn (Builder $q) => $q->whereNull('position_id'),
                fn (Builder $q) => $q->where('position_id', $positionId)
            );
        $this->constrainSource($query);

        return $query;
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $q = trim((string) $search);
        if ($q === '') {
            return;
        }

        $like = '%'.$q.'%';
        $query->where(function (Builder $builder) use ($like): void {
            $builder->where('application_reference', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('screening_status', 'like', $like)
                ->orWhere('highest_qualification', 'like', $like)
                ->orWhere('highest_qualification_detail', 'like', $like)
                ->orWhere('professional_qualifications', 'like', $like)
                ->orWhereHas('applicant', function (Builder $applicant) use ($like): void {
                    $applicant->where('full_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('national_id', 'like', $like)
                        ->orWhere('registration_number', 'like', $like)
                        ->orWhere('county', 'like', $like)
                        ->orWhere('gender', 'like', $like);
                });
        });
    }

    /**
     * @param  array{
     *   duplicate_application_ids: list<int>,
     *   unique_keep_ids: list<int>,
     *   by_application_id: array<int, array{is_duplicate: bool, is_primary: bool, match: ?string, count: int, primary_reference: ?string, label: ?string}>
     * }  $duplicateMeta
     */
    private function applyDuplicatesFilter(
        Builder $query,
        ?string $duplicates,
        array $duplicateMeta,
        ?string $match = null,
    ): void {
        $mode = strtolower(trim((string) $duplicates));
        $matchIds = $this->applicationIdsForMatchFilter($duplicateMeta, $match);
        $duplicateIds = $duplicateMeta['duplicate_application_ids'];

        if (in_array($mode, ['duplicates', 'duplicate', 'yes', '1'], true)) {
            $ids = $duplicateIds;
            if ($matchIds !== null) {
                $ids = array_values(array_intersect($ids, $matchIds));
            }
            $query->whereIn('id', $ids !== [] ? $ids : [0]);

            return;
        }

        if (in_array($mode, ['unique', 'no', '0'], true)) {
            if ($duplicateIds !== []) {
                $query->whereNotIn('id', $duplicateIds);
            }
            if ($matchIds !== null) {
                // Unique Identifier keeps that belong to groups matched by this type.
                $keepWithMatch = array_values(array_intersect(
                    $duplicateMeta['unique_keep_ids'],
                    $matchIds
                ));
                $query->whereIn('id', $keepWithMatch !== [] ? $keepWithMatch : [0]);
            }

            return;
        }

        // All applications, optionally narrowed to a match type (group members).
        if ($matchIds !== null) {
            $query->whereIn('id', $matchIds !== [] ? $matchIds : [0]);
        }
    }

    /**
     * @param  array{
     *   by_application_id: array<int, array{match: ?string}>
     * }  $duplicateMeta
     * @return list<int>|null  null = no match filter applied
     */
    private function applicationIdsForMatchFilter(array $duplicateMeta, ?string $match): ?array
    {
        $normalized = strtolower(trim((string) $match));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        // Require every active criterion (same applicant + email + ID No).
        // Same phone is intentionally not used as a duplicate signal for now.
        if (in_array($normalized, ['all_criteria', 'all_matches', 'all_four', 'complete'], true)) {
            $needles = ['same applicant', 'same email', 'same id no'];
            $ids = [];
            foreach ($duplicateMeta['by_application_id'] as $appId => $info) {
                $hay = strtolower((string) ($info['match'] ?? ''));
                $hasAll = true;
                foreach ($needles as $needle) {
                    if (! str_contains($hay, $needle)) {
                        $hasAll = false;
                        break;
                    }
                }
                if ($hasAll) {
                    $ids[] = (int) $appId;
                }
            }

            return $ids;
        }

        $needle = match ($normalized) {
            'email', 'same_email', 'same email' => 'same email',
            'national_id', 'id', 'same_id', 'same id no', 'same_id_no' => 'same id no',
            'applicant', 'same_applicant', 'same applicant' => 'same applicant',
            default => null,
        };

        if ($needle === null) {
            return [];
        }

        $ids = [];
        foreach ($duplicateMeta['by_application_id'] as $appId => $info) {
            $hay = strtolower((string) ($info['match'] ?? ''));
            if (str_contains($hay, $needle)) {
                $ids[] = (int) $appId;
            }
        }

        return $ids;
    }

    /**
     * Duplicate group details for one application (same position).
     *
     * @return array{
     *   is_duplicate: bool,
     *   is_primary: bool,
     *   label: string|null,
     *   match: string|null,
     *   primary_reference: string|null,
     *   group_size: int,
     *   related: list<array<string, mixed>>
     * }|null
     */
    public function duplicateDetailsForApplication(Application $application): ?array
    {
        $meta = $this->duplicateGroupsForPosition($application->position_id, true);
        $info = $meta['by_application_id'][$application->id] ?? null;
        if ($info === null) {
            // Still show if this app itself was hidden as a duplicate.
            if (! $application->isDuplicateHidden()) {
                return null;
            }

            return [
                'is_duplicate' => true,
                'is_primary' => false,
                'label' => $application->duplicate_of_reference
                    ? 'Duplicate — '.$application->duplicate_of_reference
                    : 'Duplicate (hidden)',
                'match' => null,
                'primary_reference' => $application->duplicate_of_reference,
                'group_size' => 1,
                'related' => [[
                    'application_id' => $application->id,
                    'application_reference' => $application->application_reference,
                    'applicant_name' => $application->applicant?->full_name,
                    'email' => $application->applicant?->email,
                    'phone' => $application->applicant?->phone,
                    'national_id' => $application->applicant?->national_id,
                    'received_at' => NairobiDate::iso($application->received_at),
                    'status' => $application->status,
                    'is_primary' => false,
                    'is_duplicate' => true,
                    'is_hidden' => true,
                    'label' => $application->duplicate_of_reference
                        ? 'Duplicate — '.$application->duplicate_of_reference
                        : 'Duplicate (hidden)',
                    'match' => null,
                ]],
            ];
        }

        $primaryReference = (string) ($info['primary_reference'] ?? '');
        $relatedIds = [];
        foreach ($meta['by_application_id'] as $appId => $memberInfo) {
            if (($memberInfo['primary_reference'] ?? null) === $primaryReference) {
                $relatedIds[] = (int) $appId;
            }
        }

        $relatedApps = Application::query()
            ->with(['applicant:id,full_name,email,phone,national_id'])
            ->whereIn('id', $relatedIds)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $related = $relatedApps->map(function (Application $app) use ($meta) {
            $member = $meta['by_application_id'][$app->id] ?? null;

            return [
                'application_id' => $app->id,
                'application_reference' => $app->application_reference,
                'applicant_name' => $app->applicant?->full_name,
                'email' => $app->applicant?->email,
                'phone' => $app->applicant?->phone,
                'national_id' => $app->applicant?->national_id,
                'received_at' => NairobiDate::iso($app->received_at),
                'status' => $app->status,
                'is_primary' => (bool) ($member['is_primary'] ?? false),
                'is_duplicate' => (bool) ($member['is_duplicate'] ?? false),
                'is_hidden' => $app->isDuplicateHidden(),
                'label' => $member['label'] ?? null,
                'match' => $member['match'] ?? null,
            ];
        })->values()->all();

        return [
            'is_duplicate' => (bool) $info['is_duplicate'],
            'is_primary' => (bool) $info['is_primary'],
            'label' => $info['label'] ?? null,
            'match' => $info['match'] ?? null,
            'primary_reference' => $primaryReference !== '' ? $primaryReference : null,
            'group_size' => count($related),
            'related' => $related,
        ];
    }

    /**
     * Hidden duplicates report (all positions).
     *
     * @return array{generated_at: string|null, total: int, rows: list<array<string, mixed>>}
     */
    public function hiddenDuplicatesReport(?int $positionId = null): array
    {
        if ($positionId !== null) {
            $this->positionScope->assertCanAccessPosition($positionId);
        }

        $query = Application::query()
            ->with([
                'applicant:id,full_name,email,phone,national_id',
                'position:id,reference_code,title',
                'duplicateHiddenBy:id,name,display_name,email',
                'duplicateOf:id,application_reference',
            ])
            ->whereNotNull('duplicate_hidden_at')
            ->orderByDesc('duplicate_hidden_at')
            ->orderBy('id');

        $this->positionScope->scopeApplicationsQuery($query);
        $this->constrainSource($query);

        if ($positionId !== null) {
            $query->where('position_id', $positionId);
        }

        $rows = $query->get()->map(function (Application $app, int $index) {
            return [
                'serial_no' => $index + 1,
                'application_id' => $app->id,
                'application_reference' => $app->application_reference,
                'position_code' => $app->position?->reference_code,
                'position_title' => $app->position?->title,
                'applicant_name' => $app->applicant?->full_name,
                'email' => $app->applicant?->email,
                'phone' => $app->applicant?->phone,
                'national_id' => $app->applicant?->national_id,
                'duplicate_of_reference' => $app->duplicate_of_reference
                    ?: $app->duplicateOf?->application_reference,
                'duplicate_of_application_id' => $app->duplicate_of_application_id,
                'hidden_at' => NairobiDate::iso($app->duplicate_hidden_at),
                'hidden_by' => $app->duplicateHiddenBy
                    ? ($app->duplicateHiddenBy->display_name ?: $app->duplicateHiddenBy->name)
                    : null,
                'received_at' => NairobiDate::iso($app->received_at),
                'status' => $app->status,
            ];
        })->values()->all();

        return [
            'generated_at' => NairobiDate::iso(now()),
            'total' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * Counts of same-email duplicates per position (no applicant rows).
     *
     * @return array{total: int, groups: int, categories: list<array<string, mixed>>}
     */
    public function emailDuplicatesSummary(): array
    {
        $groupedQuery = Application::query()
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->leftJoin('positions', 'positions.id', '=', 'applications.position_id')
            ->whereNull('applications.duplicate_hidden_at')
            ->whereNotNull('applicants.email')
            ->where('applicants.email', '!=', '');
        $this->constrainSource($groupedQuery, true);
        $grouped = $groupedQuery
            ->select([
                'applications.position_id',
                'positions.reference_code',
                'positions.title',
                'positions.sort_order',
                DB::raw('LOWER(TRIM(applicants.email)) as email_key'),
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy(
                'applications.position_id',
                'positions.reference_code',
                'positions.title',
                'positions.sort_order',
                DB::raw('LOWER(TRIM(applicants.email))')
            )
            ->havingRaw('COUNT(*) > 1')
            ->get();

        /** @var array<string, array<string, mixed>> $categoryMap */
        $categoryMap = [];
        $total = 0;
        $groups = 0;

        foreach ($grouped as $row) {
            $email = strtolower(trim((string) $row->email_key));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $allowed = $this->positionScope->allowedPositionIds();
            if ($allowed !== null) {
                if ($row->position_id === null || ! in_array((int) $row->position_id, $allowed, true)) {
                    continue;
                }
            }

            $groups++;
            $extras = max(0, (int) $row->total - 1);
            $total += $extras;
            $key = $row->position_id === null ? 'unassigned' : (string) $row->position_id;
            if (! isset($categoryMap[$key])) {
                $categoryMap[$key] = [
                    'key' => $key,
                    'position_id' => $row->position_id !== null ? (int) $row->position_id : null,
                    'reference_code' => $row->reference_code,
                    'title' => $row->title ?? 'Unassigned / category not identified',
                    'sort_order' => (int) ($row->sort_order ?? 9999),
                    'groups' => 0,
                    'duplicate_applicants' => 0,
                ];
            }
            $categoryMap[$key]['groups']++;
            $categoryMap[$key]['duplicate_applicants'] += $extras;
        }

        $categories = collect($categoryMap)
            ->sortBy([
                ['sort_order', 'asc'],
                ['key', 'asc'],
            ])
            ->values()
            ->map(function (array $category) {
                unset($category['sort_order']);

                return $category;
            })
            ->all();

        return [
            'total' => $total,
            'groups' => $groups,
            'categories' => $categories,
        ];
    }

    /**
     * Duplicates defined only by the same email address within a position.
     * Earliest application is the Unique Identifier; later ones are duplicates.
     *
     * @return array{
     *   generated_at: string|null,
     *   total: int,
     *   groups: int,
     *   categories: list<array<string, mixed>>,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function emailDuplicatesReport(?int $positionId = null): array
    {
        if ($positionId !== null) {
            $this->positionScope->assertCanAccessPosition($positionId);
        }

        $query = Application::query()
            ->with([
                'applicant:id,full_name,email,phone,national_id',
                'position:id,reference_code,title,sort_order',
            ])
            ->whereNull('duplicate_hidden_at')
            ->whereHas('applicant', function (Builder $applicant): void {
                $applicant->whereNotNull('email')->where('email', '!=', '');
            })
            ->orderBy('received_at')
            ->orderBy('id');

        $this->positionScope->scopeApplicationsQuery($query);
        $this->constrainSource($query);

        if ($positionId !== null) {
            $query->where('position_id', $positionId);
        }

        /** @var array<string, list<Application>> $buckets */
        $buckets = [];
        foreach ($query->get() as $app) {
            $email = strtolower(trim((string) ($app->applicant?->email ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $posKey = $app->position_id === null ? 'unassigned' : (string) $app->position_id;
            $buckets[$posKey.'|'.$email][] = $app;
        }

        /** @var array<string, array<string, mixed>> $categoryMap */
        $categoryMap = [];
        $rows = [];
        $groups = 0;

        foreach ($buckets as $members) {
            if (count($members) < 2) {
                continue;
            }

            $groups++;
            $primary = $members[0];
            $key = $primary->position_id === null ? 'unassigned' : (string) $primary->position_id;
            if (! isset($categoryMap[$key])) {
                $categoryMap[$key] = [
                    'key' => $key,
                    'position_id' => $primary->position_id,
                    'reference_code' => $primary->position?->reference_code,
                    'title' => $primary->position?->title ?? 'Unassigned / category not identified',
                    'sort_order' => (int) ($primary->position?->sort_order ?? 9999),
                    'groups' => 0,
                    'duplicate_applicants' => 0,
                ];
            }

            $extras = count($members) - 1;
            $categoryMap[$key]['groups']++;
            $categoryMap[$key]['duplicate_applicants'] += $extras;

            $primaryId = (int) $primary->id;
            $primaryReference = (string) $primary->application_reference;
            $groupSize = count($members);

            foreach (array_slice($members, 1) as $dup) {
                $rows[] = [
                    'serial_no' => 0,
                    'application_id' => $dup->id,
                    'application_reference' => $dup->application_reference,
                    'position_key' => $key,
                    'position_code' => $dup->position?->reference_code,
                    'position_title' => $dup->position?->title ?? 'Unassigned / category not identified',
                    'applicant_name' => $dup->applicant?->full_name,
                    'email' => $dup->applicant?->email,
                    'phone' => $dup->applicant?->phone,
                    'national_id' => $dup->applicant?->national_id,
                    'duplicate_of_reference' => $primaryReference,
                    'duplicate_of_application_id' => $primaryId,
                    'group_size' => $groupSize,
                    'received_at' => NairobiDate::iso($dup->received_at),
                    'status' => $dup->status,
                ];
            }
        }

        $categories = collect($categoryMap)
            ->sortBy([
                ['sort_order', 'asc'],
                ['key', 'asc'],
            ])
            ->values()
            ->map(function (array $category) {
                unset($category['sort_order']);

                return $category;
            })
            ->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['serial_no'] = $index + 1;
        }

        return [
            'generated_at' => NairobiDate::iso(now()),
            'total' => count($rows),
            'groups' => $groups,
            'categories' => $categories,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{
     *   by_application_id: array<int, array{is_duplicate: bool, is_primary: bool, match: ?string, count: int, primary_reference: ?string, label: ?string}>
     * }  $duplicateMeta
     * @return array<string, mixed>
     */
    private function withDuplicateFields(array $row, int $applicationId, array $duplicateMeta): array
    {
        $info = $duplicateMeta['by_application_id'][$applicationId] ?? null;
        $row['is_duplicate'] = (bool) ($info['is_duplicate'] ?? false);
        $row['is_duplicate_primary'] = (bool) ($info['is_primary'] ?? false);
        $row['duplicate_match'] = $info['match'] ?? null;
        $row['duplicate_count'] = $info['count'] ?? null;
        $row['duplicate_of'] = $info['primary_reference'] ?? null;
        $row['duplicate_label'] = $info['label'] ?? null;

        return $row;
    }

    /**
     * Within a position, group applications that share applicant/email/ID No.
     * Same phone is not treated as a duplicate signal for now (too many shared numbers).
     * Earliest application (received_at, then id) is kept as the Unique Identifier;
     * others are labeled "Duplicate — {Unique Identifier}".
     *
     * @return array{
     *   duplicate_application_ids: list<int>,
     *   unique_keep_ids: list<int>,
     *   by_application_id: array<int, array{is_duplicate: bool, is_primary: bool, match: ?string, count: int, primary_reference: ?string, label: ?string}>
     * }
     */
    private function duplicateGroupsForPosition(?int $positionId, bool $includeHidden = false): array
    {
        $rows = Application::query()
            ->leftJoin('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->when(
                $positionId === null,
                fn (Builder $q) => $q->whereNull('applications.position_id'),
                fn (Builder $q) => $q->where('applications.position_id', $positionId)
            )
            ->when(! $includeHidden, fn (Builder $q) => $q->whereNull('applications.duplicate_hidden_at'));
        $this->constrainSource($rows, true);
        $rows = $rows
            ->orderBy('applications.received_at')
            ->orderBy('applications.id')
            ->get([
                'applications.id as application_id',
                'applications.application_reference',
                'applications.received_at',
                'applications.applicant_id',
                'applicants.email',
                'applicants.national_id',
            ]);

        /** @var array<int, array{id: int, reference: string, received_at: ?string}> $meta */
        $meta = [];
        /** @var array<string, list<int>> $keyMembers */
        $keyMembers = [];

        foreach ($rows as $row) {
            $appId = (int) $row->application_id;
            $meta[$appId] = [
                'id' => $appId,
                'reference' => (string) $row->application_reference,
                'received_at' => $row->received_at?->toDateTimeString(),
            ];

            $keys = [];
            if (! empty($row->applicant_id)) {
                $keys[] = 'applicant:'.(int) $row->applicant_id;
            }
            $email = strtolower(trim((string) ($row->email ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $keys[] = 'email:'.$email;
            }
            $nationalId = preg_replace('/\D+/', '', (string) ($row->national_id ?? '')) ?? '';
            if (strlen($nationalId) >= 6) {
                $keys[] = 'national_id:'.$nationalId;
            }

            foreach ($keys as $key) {
                $keyMembers[$key][] = $appId;
            }
        }

        // Union-find so overlapping keys (email + ID No) form one cluster.
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        /** @var array<int, array<string, true>> $matchLabels */
        $matchLabels = [];

        foreach ($keyMembers as $key => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            if (count($uniqueIds) < 2) {
                continue;
            }

            $label = match (true) {
                str_starts_with($key, 'applicant:') => 'same applicant',
                str_starts_with($key, 'email:') => 'same email',
                str_starts_with($key, 'national_id:') => 'same ID No',
                default => 'duplicate',
            };

            $first = $uniqueIds[0];
            foreach ($uniqueIds as $id) {
                $union($first, $id);
                $matchLabels[$id][$label] = true;
            }
        }

        /** @var array<int, list<int>> $clusters */
        $clusters = [];
        foreach (array_keys($matchLabels) as $appId) {
            $clusters[$find($appId)][] = $appId;
        }

        $byApplicationId = [];
        $duplicateApplicationIds = [];
        $uniqueKeepIds = [];

        foreach ($clusters as $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }

            usort($members, function (int $a, int $b) use ($meta): int {
                $ra = $meta[$a]['received_at'] ?? '';
                $rb = $meta[$b]['received_at'] ?? '';
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }

                return $a <=> $b;
            });

            $primaryId = $members[0];
            $primaryReference = $meta[$primaryId]['reference'];
            $count = count($members);

            foreach ($members as $appId) {
                $match = implode(', ', array_keys($matchLabels[$appId] ?? ['duplicate' => true]));
                $isPrimary = $appId === $primaryId;

                if ($isPrimary) {
                    $uniqueKeepIds[] = $appId;
                    $byApplicationId[$appId] = [
                        'is_duplicate' => false,
                        'is_primary' => true,
                        'match' => $match,
                        'count' => $count,
                        'primary_reference' => $primaryReference,
                        'label' => 'Unique Identifier',
                    ];
                } else {
                    $duplicateApplicationIds[] = $appId;
                    $byApplicationId[$appId] = [
                        'is_duplicate' => true,
                        'is_primary' => false,
                        'match' => $match,
                        'count' => $count,
                        'primary_reference' => $primaryReference,
                        'label' => 'Duplicate — '.$primaryReference,
                    ];
                }
            }
        }

        return [
            'duplicate_application_ids' => $duplicateApplicationIds,
            'unique_keep_ids' => $uniqueKeepIds,
            'by_application_id' => $byApplicationId,
        ];
    }

    private function applyQualificationFilter(Builder $query, ?string $qualification): void
    {
        $value = strtolower(trim((string) $qualification));
        if ($value === '') {
            return;
        }

        if (in_array($value, ['none', 'blank', 'unknown'], true)) {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('highest_qualification')
                    ->orWhere('highest_qualification', '');
            });

            return;
        }

        $allowed = ['phd', 'masters', 'bachelors', 'higher_diploma', 'diploma', 'certificate', 'kcse'];
        if (! in_array($value, $allowed, true)) {
            return;
        }

        $query->where('highest_qualification', $value);
    }

    private function applyDocumentsFilter(Builder $query, ?string $documents): void
    {
        $query->documentsFilter($documents);
    }

    /**
     * @return Collection<int, Application>
     */
    private function applicationsForPosition(?int $positionId): Collection
    {
        return $this->baseQuery($positionId)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return array<string, mixed>
     */
    private function categoryPayload(Position $position, Collection $applications): array
    {
        return [
            'position_id' => $position->id,
            'reference_code' => $position->reference_code,
            'title' => $position->title,
            'department' => $position->department,
            'vacancies' => $position->vacancies,
            'total_applicants' => $applications->count(),
            'rows' => $applications->values()->map(fn (Application $app, int $index) => $this->row($app, $index + 1))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Application $application, int $serial): array
    {
        $applicant = $application->applicant;
        $receivedAsOne = $this->receivedAsOneYesNo($application);
        $pwd = $this->pwdYesNo($applicant);
        $academic = $this->academicQualifications($application);
        $professional = $this->professionalQualifications($application);
        $remarks = $this->commentsRemarks($application, $applicant, $pwd);

        return [
            'serial_no' => $serial,
            'application_id' => $application->id,
            'application_reference' => $application->application_reference,
            'applicant_name' => $this->displayApplicantName($application),
            'phone' => $this->displayPhone($applicant?->phone),
            'email' => $this->displayApplicantEmail($application),
            'national_id' => $applicant?->national_id,
            'pwd' => $pwd,
            'county' => \App\Support\KenyaCounties::normalize($applicant?->county),
            'gender' => $applicant?->gender,
            'received_as_one' => $receivedAsOne,
            'academic_qualifications' => $academic,
            'professional_membership' => $professional,
            'computer_proficiency' => $this->displayComputerProficiency($application->computer_proficiency),
            'experience_years' => $application->experience_years !== null
                ? rtrim(rtrim(number_format((float) $application->experience_years, 1, '.', ''), '0'), '.')
                : null,
            'comments_remarks' => $remarks,
            // retained for UI convenience
            'registration_number' => $applicant?->registration_number,
            'received_at' => NairobiDate::format($application->received_at),
            'received_at_iso' => NairobiDate::iso($application->received_at),
            'status' => $application->status,
            'screening_status' => $application->screening_status,
            'documents_count' => $application->documents->count(),
            'subject' => $application->subject,
            'notes' => $application->notes,
        ];
    }

    private function displayApplicantName(Application $application): ?string
    {
        $name = trim((string) ($application->applicant?->full_name ?? ''));
        $lower = strtolower($name);

        if ($name !== '' && $lower !== 'careerjet' && ! str_starts_with($lower, 'unnamed applicant')) {
            return $name;
        }

        $fromSubject = app(\App\Services\Applications\JobBoardApplicantResolver::class)
            ->nameFromSubject($application->subject);

        return $fromSubject ?? ($name !== '' ? $name : null);
    }

    private function displayApplicantEmail(Application $application): ?string
    {
        $email = strtolower(trim((string) ($application->applicant?->email ?? '')));
        if ($email === '' || str_contains($email, 'careerjet') || str_contains($email, 'noreply')) {
            return null;
        }

        return $application->applicant?->email;
    }

    private function displayPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        return app(\App\Services\Applications\ApplicationProfileExtractor::class)
            ->normalizePhoneForDisplay($phone);
    }

    private function displayComputerProficiency(?string $value): ?string
    {
        return app(\App\Services\Applications\ApplicationProfileExtractor::class)
            ->normalizeComputerProficiencyForDisplay($value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, scalar|null>
     */
    private function csvMap(?string $code, ?string $title, array $row, bool $includeCategory = false): array
    {
        $mapped = [
            'SN.' => $row['serial_no'],
            'Unique Identifier' => $row['application_reference'],
            'Applicant Name' => $row['applicant_name'],
            'Telephone/Mobile No' => $row['phone'],
            'Email' => $row['email'],
            'ID No' => $row['national_id'],
            'PWD(Yes/No)' => $row['pwd'],
            'County of Origin' => $row['county'],
            'Gender' => $row['gender'],
            'Status of the application received as one or in pieces(Cover letter, CV & Certificates) Yes/No' => $row['received_as_one'],
            'Academic Qualifications' => $row['academic_qualifications'],
            'Professional Membership' => $row['professional_membership'],
            'Proficiency in Computer Studies' => $row['computer_proficiency'],
            'Years of Working Experience' => $row['experience_years'],
            'Comments/Remarks-- for PWD must indicated or attach in the certificates' => $this->commentsWithDuplicate($row),
            'Documents' => (int) ($row['documents_count'] ?? 0),
        ];

        if ($includeCategory) {
            return [
                'Category Code' => $code,
                'Category / Position' => $title,
                ...$mapped,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function commentsWithDuplicate(array $row): ?string
    {
        $parts = [];
        if (! empty($row['duplicate_label'])) {
            $parts[] = (string) $row['duplicate_label']
                .(filled($row['duplicate_match'] ?? null) ? ' ('.$row['duplicate_match'].')' : '');
        } elseif (! empty($row['is_duplicate']) && filled($row['duplicate_of'] ?? null)) {
            $parts[] = 'Duplicate — '.$row['duplicate_of'];
        }
        if (filled($row['comments_remarks'] ?? null)) {
            $parts[] = (string) $row['comments_remarks'];
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private function receivedAsOneYesNo(Application $application): ?string
    {
        $docs = $application->relationLoaded('documents')
            ? $application->documents->count()
            : $application->documents()->count();

        if ($docs <= 0) {
            if (strcasecmp((string) $application->source, 'myjobs') === 0
                || strcasecmp((string) $application->nature_of_application, 'one') === 0) {
                return 'Yes';
            }

            return null; // shown as — in UI / blank in CSV
        }

        // One submitted document → Yes (as one); more than one → No (in pieces)
        return $docs === 1 ? 'Yes' : 'No';
    }

    private function pwdYesNo(?\App\Models\Applicant $applicant): ?string
    {
        if ($applicant === null || $applicant->is_pwd === null) {
            return null;
        }

        return $applicant->is_pwd ? 'Yes' : 'No';
    }

    private function academicQualifications(Application $application): ?string
    {
        $level = strtolower(trim((string) $application->highest_qualification));

        return match ($level) {
            'phd', 'doctorate', 'doctoral' => 'PhD',
            'masters', 'master' => 'Masters',
            'bachelors', 'bachelor' => 'Bachelors',
            'higher_diploma', 'higher diploma', 'hnd' => 'Higher Diploma',
            'diploma' => 'Diploma',
            'certificate', 'craft' => 'Certificate',
            'kcse' => 'KCSE',
            default => $this->inferAcademicLevel(
                (string) ($application->highest_qualification_detail ?: $application->highest_qualification)
            ),
        };
    }

    private function inferAcademicLevel(string $text): ?string
    {
        $hay = strtolower(trim($text));
        if ($hay === '') {
            return null;
        }

        if (preg_match('/\b(ph\.?\s*d|doctorate|doctoral)\b/iu', $hay)) {
            return 'PhD';
        }
        if (preg_match('/\b(master\'?s|masters|m\.?sc|mba|m\.?a\.?|llm|mph)\b/iu', $hay)) {
            return 'Masters';
        }
        if (preg_match('/\b(bachelor\'?s|bachelors|b\.?sc|b\.?a\.?|b\.?com|llb|undergraduate)\b/iu', $hay)) {
            return 'Bachelors';
        }
        if (preg_match('/\b(higher\s+national\s+diploma|higher\s+diploma|h\.?\s*n\.?\s*d\.?)\b/iu', $hay)) {
            return 'Higher Diploma';
        }
        if (preg_match('/\b(national\s+diploma|diploma)\b/iu', $hay)) {
            return 'Diploma';
        }
        if (preg_match('/\b(k\.?\s*c\.?\s*s\.?\s*e\.?|kenya\s+certificate\s+of\s+secondary|form\s*(?:iv|4))\b/iu', $hay)) {
            return 'KCSE';
        }
        if (preg_match('/\b(certificate|craft)\b/iu', $hay)) {
            return 'Certificate';
        }

        return null;
    }

    private function professionalQualifications(Application $application): ?string
    {
        // Not mandatory for REC11 / REC12 / REC13.
        $application->loadMissing('position:id,reference_code');
        $code = strtoupper(trim((string) ($application->position?->reference_code ?? '')));
        if (in_array($code, ['NCK/REC11', 'NCK/REC12', 'NCK/REC13'], true)) {
            return null;
        }

        $stated = trim((string) $application->professional_membership);
        if ($stated === '') {
            return null;
        }

        if (preg_match('/^\s*(no|none|nil|n\/a|not\s+applicable|not\s+a\s+member)\s*\.?$/iu', $stated)) {
            return 'No';
        }

        // List what the applicant stated (keep it short for the report grid).
        return \Illuminate\Support\Str::limit($stated, 120, '');
    }

    private function commentsRemarks(
        Application $application,
        ?\App\Models\Applicant $applicant,
        ?string $pwd,
    ): ?string {
        $parts = [];

        if (filled($application->notes)) {
            $parts[] = trim((string) $application->notes);
        } elseif ($this->isViaCareerjet($application, $applicant)) {
            $parts[] = 'Received via Careerjet';
        }

        if ($pwd === 'Yes') {
            $pwdNote = filled($applicant?->pwd_details)
                ? 'PWD: '.trim((string) $applicant->pwd_details)
                : 'PWD: Yes — disability status must be indicated / certificate attached';
            $parts[] = $pwdNote;
        }

        $docs = $application->relationLoaded('documents')
            ? $application->documents->count()
            : $application->documents()->count();
        if ($docs > 1) {
            $parts[] = "Received in pieces ({$docs} documents)";
        }

        if ($parts === []) {
            return null;
        }

        return implode(' | ', $parts);
    }

    private function isViaCareerjet(Application $application, ?\App\Models\Applicant $applicant): bool
    {
        if (strcasecmp((string) $application->source, 'careerjet') === 0) {
            return true;
        }

        $metaSource = data_get($applicant?->meta, 'source');
        if (is_string($metaSource) && strcasecmp($metaSource, 'careerjet') === 0) {
            return true;
        }

        $application->loadMissing('mailMessage');
        $email = strtolower((string) ($application->mailMessage?->sender_email ?? ''));

        return str_contains($email, 'careerjet');
    }
}
