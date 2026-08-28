<?php

namespace App\Services\Shortlisting;

use App\Models\Application;
use App\Models\Position;
use App\Services\Access\PositionScopeService;
use App\Support\NairobiDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ShortlistingExportService
{
    public function __construct(private readonly PositionScopeService $positionScope)
    {
    }

    /**
     * @return list<string>
     */
    public function headers(bool $includePosition = true): array
    {
        $headers = [
            'SN.',
            'Reference',
            'Applicant Name',
            'Email',
            'Telephone/Mobile No',
            'ID No',
            'Gender',
            'County',
            'PWD (Yes/No)',
        ];

        if ($includePosition) {
            $headers[] = 'Position';
            $headers[] = 'Position Code';
        }

        return array_merge($headers, [
            'Screening Status',
            'Application Status',
            'Source',
            'Received At',
        ]);
    }

    /**
     * @return list<list<mixed>>
     */
    public function rows(Request $request, bool $includePosition = true): array
    {
        $applications = $this->orderedQuery($request)->get();
        $rows = [];

        foreach ($applications->values() as $index => $application) {
            $rows[] = $this->mapRow($application, $index + 1, $includePosition);
        }

        return $rows;
    }

    /**
     * @return list<array{sheet_name: string, position_id: int|null, reference_code: string|null, title: string, total: int, rows: list<list<mixed>>}>
     */
    public function rowsByPosition(Request $request): array
    {
        $applications = $this->orderedQuery($request)->get();
        $groups = [];

        foreach ($applications->groupBy('position_id') as $positionId => $items) {
            /** @var Application $first */
            $first = $items->first();
            $position = $first->position;
            $title = $position?->title ?? 'Unassigned';
            $code = $position?->reference_code;

            $rows = [];
            foreach ($items->values() as $index => $application) {
                $rows[] = $this->mapRow($application, $index + 1, false);
            }

            $groups[] = [
                'sheet_name' => ($code ?: 'UNASSIGNED').' · '.$title,
                'position_id' => $positionId ? (int) $positionId : null,
                'reference_code' => $code,
                'title' => $title,
                'total' => count($rows),
                'rows' => $rows,
            ];
        }

        usort($groups, fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $groups;
    }

    /**
     * @return list<array{position_id: int|null, reference_code: string|null, title: string, total: int, shortlisted: int, queue: int}>
     */
    public function positionSummary(Request $request): array
    {
        $validated = $this->validatedFilters($request);
        $query = Application::query()
            ->leftJoin('positions', 'positions.id', '=', 'applications.position_id');

        $this->positionScope->scopeApplicationsQuery($query);
        $this->applyViewFilter($query, $validated['view'] ?? 'all');

        $rows = $query
            ->selectRaw('applications.position_id as position_id')
            ->selectRaw('positions.reference_code as reference_code')
            ->selectRaw('positions.title as title')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN applications.status = ? THEN 1 ELSE 0 END) as shortlisted", [Application::STATUS_SHORTLISTED])
            ->selectRaw("SUM(CASE WHEN applications.status != ? AND applications.screening_status = 'passed' THEN 1 ELSE 0 END) as queue", [Application::STATUS_SHORTLISTED])
            ->groupBy('applications.position_id', 'positions.reference_code', 'positions.title')
            ->orderByRaw('COALESCE(positions.title, ?) asc', ['Unassigned'])
            ->get();

        return $rows->map(fn ($row) => [
            'position_id' => $row->position_id !== null ? (int) $row->position_id : null,
            'reference_code' => $row->reference_code,
            'title' => $row->title ?? 'Unassigned / no position',
            'total' => (int) $row->total,
            'shortlisted' => (int) $row->shortlisted,
            'queue' => (int) $row->queue,
        ])->values()->all();
    }

    /**
     * @return list<array{position_id: int|null, reference_code: string|null, title: string, total: int, candidates: list<array<string, mixed>>}>
     */
    public function grouped(Request $request): array
    {
        $applications = $this->orderedQuery($request)->get();
        $groups = [];

        foreach ($applications->groupBy('position_id') as $positionId => $items) {
            /** @var Application $first */
            $first = $items->first();
            $position = $first->position;

            $groups[] = [
                'position_id' => $positionId ? (int) $positionId : null,
                'reference_code' => $position?->reference_code,
                'title' => $position?->title ?? 'Unassigned / no position',
                'total' => $items->count(),
                'candidates' => $items->values()->map(fn (Application $application) => $this->mapCandidate($application))->all(),
            ];
        }

        usort($groups, fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $groups;
    }

    public function subtitle(Request $request, int $count): string
    {
        $validated = $this->validatedFilters($request);

        $parts = [$count.' candidate row(s)'];

        $view = $validated['view'] ?? 'all';
        $parts[] = match ($view) {
            'shortlisted' => 'Shortlisted only',
            'queue' => 'Screening passed · not yet shortlisted',
            default => 'Shortlisted and screening passed',
        };

        if (! empty($validated['position_id'])) {
            $position = Position::query()->find((int) $validated['position_id']);
            if ($position) {
                $parts[] = ($position->reference_code ?: 'Position').' · '.$position->title;
            }
        } else {
            $parts[] = 'Grouped by position';
        }

        return implode(' · ', $parts);
    }

    /**
     * @return Builder<Application>
     */
    public function query(Request $request): Builder
    {
        return $this->orderedQuery($request);
    }

    /**
     * @return Builder<Application>
     */
    private function orderedQuery(Request $request): Builder
    {
        $validated = $this->validatedFilters($request);

        if (! empty($validated['position_id'])) {
            $this->positionScope->assertCanAccessPosition((int) $validated['position_id']);
        }

        $query = Application::query()
            ->with(['applicant', 'position'])
            ->leftJoin('positions as shortlist_positions', 'shortlist_positions.id', '=', 'applications.position_id')
            ->select('applications.*')
            ->orderByRaw('COALESCE(shortlist_positions.title, ?) asc', ['Unassigned'])
            ->orderByDesc('applications.received_at');

        $this->positionScope->scopeApplicationsQuery($query);
        $this->applyViewFilter($query, $validated['view'] ?? 'all');

        if (! empty($validated['position_id'])) {
            $query->where('applications.position_id', (int) $validated['position_id']);
        }

        return $query;
    }

    /**
     * @return array{position_id?: int, view?: string}
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'view' => ['nullable', 'string', 'in:all,queue,shortlisted'],
        ]);
    }

    private function applyViewFilter(Builder $query, string $view): void
    {
        if ($view === 'shortlisted') {
            $query->where('applications.status', Application::STATUS_SHORTLISTED);

            return;
        }

        if ($view === 'queue') {
            $query->where('applications.screening_status', 'passed')
                ->where('applications.status', '!=', Application::STATUS_SHORTLISTED);

            return;
        }

        $query->where(function (Builder $builder): void {
            $builder->where('applications.status', Application::STATUS_SHORTLISTED)
                ->orWhere('applications.screening_status', 'passed');
        });
    }

    /**
     * @return list<mixed>
     */
    private function mapRow(Application $application, int $serial, bool $includePosition): array
    {
        $applicant = $application->applicant;
        $position = $application->position;

        $row = [
            $serial,
            $application->application_reference,
            $applicant?->full_name ?? '',
            $applicant?->email ?? '',
            $applicant?->phone ?? '',
            $applicant?->national_id ?? '',
            $applicant?->gender ?? '',
            $applicant?->county ?? '',
            $applicant?->is_pwd ? 'Yes' : 'No',
        ];

        if ($includePosition) {
            $row[] = $position?->title ?? '';
            $row[] = $position?->reference_code ?? '';
        }

        return array_merge($row, [
            $application->screening_status ?? '',
            $application->status ?? '',
            $application->source ?? '',
            NairobiDate::format($application->received_at) ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(Application $application): array
    {
        return [
            'id' => $application->id,
            'uuid' => $application->uuid,
            'application_reference' => $application->application_reference,
            'subject' => $application->subject,
            'status' => $application->status,
            'screening_status' => $application->screening_status,
            'received_at' => NairobiDate::iso($application->received_at),
            'applicant' => $application->applicant ? [
                'id' => $application->applicant->id,
                'full_name' => $application->applicant->full_name,
                'email' => $application->applicant->email,
            ] : null,
            'position' => $application->position ? [
                'id' => $application->position->id,
                'title' => $application->position->title,
                'reference_code' => $application->position->reference_code,
            ] : null,
        ];
    }
}

