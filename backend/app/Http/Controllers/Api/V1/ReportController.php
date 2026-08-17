<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Services\Access\PositionScopeService;
use App\Services\MyJobs\MyJobsListingService;
use App\Services\Reports\LongListingReportService;
use App\Support\ApiResponse;
use App\Support\Excel\NckReportExcel;
use App\Support\NairobiDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly LongListingReportService $longListing,
        private readonly PositionScopeService $positionScope,
        private readonly MyJobsListingService $myJobsListing,
    ) {
    }

    public function summary(): JsonResponse
    {
        $tz = NairobiDate::TZ;
        $now = Carbon::now($tz);
        $weekStart = $now->copy()->startOfWeek()->utc();
        $monthStart = $now->copy()->startOfMonth()->utc();

        $byStatusQuery = Application::query()
            ->notMyJobs()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status');

        $this->positionScope->scopeApplicationsQuery($byStatusQuery);

        $byStatus = $byStatusQuery->pluck('total', 'status');

        $byPositionQuery = Application::query()
            ->notMyJobs('applications.source')
            ->leftJoin('positions', 'positions.id', '=', 'applications.position_id')
            ->select(
                'applications.position_id',
                'positions.title',
                'positions.reference_code',
                'positions.vacancies',
                DB::raw('COUNT(applications.id) as total')
            )
            ->groupBy(
                'applications.position_id',
                'positions.title',
                'positions.reference_code',
                'positions.vacancies',
                'positions.sort_order'
            )
            ->orderBy('positions.sort_order')
            ->orderByDesc('total');

        $this->positionScope->scopeApplicationsQuery($byPositionQuery);

        $byPosition = $byPositionQuery->get()
            ->map(fn ($row) => [
                'position_id' => $row->position_id,
                'title' => $row->title ?? 'Unassigned',
                'reference_code' => $row->reference_code,
                'vacancies' => $row->vacancies,
                'total' => (int) $row->total,
            ]);

        $weekQuery = Application::query()->notMyJobs()->where('received_at', '>=', $weekStart);
        $this->positionScope->scopeApplicationsQuery($weekQuery);

        $monthQuery = Application::query()->notMyJobs()->where('received_at', '>=', $monthStart);
        $this->positionScope->scopeApplicationsQuery($monthQuery);

        $myJobsQuery = Application::query()->myJobs();
        $this->positionScope->scopeApplicationsQuery($myJobsQuery);

        $mailboxDocuments = $this->documentCounts(false);
        $myJobsDocuments = $this->documentCounts(true);

        $payload = [
            'counts_by_status' => $byStatus,
            'counts_by_position' => $byPosition,
            'email_duplicates' => $this->longListing->emailDuplicatesSummary(),
            'applications_this_week' => $weekQuery->count(),
            'applications_this_month' => $monthQuery->count(),
            'myjobs_total' => $myJobsQuery->count(),
            'documents' => [
                'mailbox_with' => $mailboxDocuments['with'],
                'mailbox_without' => $mailboxDocuments['without'],
                'myjobs_with' => $myJobsDocuments['with'],
                'myjobs_without' => $myJobsDocuments['without'],
            ],
            'generated_at' => NairobiDate::iso($now),
        ];

        if (! $this->positionScope->isRestricted()) {
            $payload['myjobs_channels'] = $this->myJobsListing->totals();
            $attachmentStats = MailAttachment::query()
                ->select('download_status', DB::raw('COUNT(*) as total'))
                ->groupBy('download_status')
                ->pluck('total', 'download_status');

            $payload['mailbox'] = [
                'messages_total' => MailMessage::query()->count(),
                'messages_pending_application' => MailMessage::query()->where('application_created', false)->count(),
                'attachments_by_status' => $attachmentStats,
                'attachments_pending' => MailAttachment::query()->where('download_status', 'pending')->count(),
                'attachments_failed' => MailAttachment::query()->where('download_status', 'failed')->count(),
                'attachments_downloaded' => MailAttachment::query()->where('download_status', 'downloaded')->count(),
            ];
        }

        return ApiResponse::success($payload);
    }

    public function longListing(Request $request): JsonResponse
    {
        $source = $this->listingSource($request);
        $this->longListing->usingSource($source);

        $report = $this->longListing->categoryIndex(
            $source !== 'myjobs' && $request->boolean('include_unassigned', true),
        );

        return ApiResponse::success($report);
    }

    public function longListingCategory(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:64'],
            'duplicates' => ['nullable', 'string', 'max:32'],
            'match' => ['nullable', 'string', 'max:32'],
            'documents' => ['nullable', 'string', 'in:with,without,has,none,yes,no,missing'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->longListing->usingSource($this->listingSource($request));

        $payload = $this->longListing->paginateCategory(
            $category,
            $validated['q'] ?? null,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
            $validated['qualification'] ?? null,
            $validated['duplicates'] ?? null,
            $validated['match'] ?? null,
            $validated['documents'] ?? null,
        );

        return ApiResponse::success($payload);
    }

    public function longListingExport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'category' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:64'],
            'duplicates' => ['nullable', 'string', 'max:32'],
            'match' => ['nullable', 'string', 'max:32'],
            'documents' => ['nullable', 'string', 'in:with,without,has,none,yes,no,missing'],
            'include_unassigned' => ['nullable', 'boolean'],
            'unassigned_only' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'in:mailbox,myjobs'],
        ]);

        $this->longListing->usingSource($this->listingSource($request));

        $headers = $this->longListing->csvHeaders();

        if (! empty($validated['category'])) {
            $category = (string) $validated['category'];
            $rows = $this->longListing->csvRowsForCategory(
                $category,
                $validated['q'] ?? null,
                $validated['qualification'] ?? null,
                $validated['duplicates'] ?? null,
                $validated['match'] ?? null,
                $validated['documents'] ?? null,
            );
            $suffix = $category === 'unassigned' ? 'unassigned' : $category;
        } elseif ($request->boolean('unassigned_only')) {
            $headers = $this->longListing->csvHeaders(false);
            $rows = $this->longListing->csvRows(null, true, true);
            $suffix = 'unassigned';
        } else {
            $positionId = isset($validated['position_id']) ? (int) $validated['position_id'] : null;
            $includeCategory = $positionId === null;
            $headers = $this->longListing->csvHeaders($includeCategory);
            $rows = $this->longListing->csvRows(
                $positionId,
                $request->boolean('include_unassigned', true),
            );
            $suffix = $positionId ? "position_{$positionId}" : 'all_categories';
        }

        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');
        $sourcePrefix = $this->longListing->listingSource() === 'myjobs' ? 'nck_myjobs_long_listing_' : 'nck_long_listing_';
        $filename = $sourcePrefix.preg_replace('/[^A-Za-z0-9_\-]+/', '_', $suffix)."_{$stamp}.xls";
        $subtitle = count($rows).' applicant row(s)';
        if ($this->longListing->listingSource() === 'myjobs') {
            $subtitle .= ' · MyJobs applications';
        }
        if (! empty($validated['q'])) {
            $subtitle .= ' · search “'.$validated['q'].'”';
        }
        if (! empty($validated['qualification'])) {
            $subtitle .= ' · '.$validated['qualification'];
        }
        if (! empty($validated['duplicates'])) {
            $subtitle .= ' · '.$validated['duplicates'];
        }

        $commentKey = 'Comments/Remarks-- for PWD must indicated or attach in the certificates';

        return (new NckReportExcel(
            $this->longListing->listingSource() === 'myjobs' ? 'MyJobs long listing' : 'Long listing',
            $subtitle,
        ))
            ->addSheet('Long listing', $headers, $rows, [
                'highlight' => function (array $row) use ($commentKey): ?string {
                    $comments = strtolower((string) ($row[$commentKey] ?? ''));
                    if (str_contains($comments, 'duplicate')) {
                        return 'duplicate';
                    }

                    return null;
                },
            ])
            ->download($filename);
    }

    public function emailDuplicates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $report = $this->longListing->emailDuplicatesReport(
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        return ApiResponse::success($report);
    }

    public function emailDuplicatesExport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $report = $this->longListing->emailDuplicatesReport(
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        $headers = [
            'SN.',
            'Unique Identifier',
            'Duplicate of Unique Identifier',
            'Category Code',
            'Category / Position',
            'Applicant Name',
            'Email',
            'Telephone/Mobile No',
            'ID No',
            'Same-email group size',
            'Status',
            'Received At',
        ];

        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');
        $filename = "nck_email_duplicates_{$stamp}.xls";
        $excelRows = [];
        foreach ($report['rows'] as $row) {
            $excelRows[] = [
                $row['serial_no'],
                $row['application_reference'],
                $row['duplicate_of_reference'],
                $row['position_code'],
                $row['position_title'],
                $row['applicant_name'],
                $row['email'],
                $row['phone'],
                $row['national_id'],
                $row['group_size'],
                $row['status'],
                $row['received_at'],
            ];
        }

        return (new NckReportExcel(
            'Duplicates — same email',
            ($report['total'] ?? count($excelRows)).' duplicate application(s) · '.($report['groups'] ?? 0).' shared email(s)',
        ))
            ->addSheet('Same email duplicates', $headers, $excelRows, [
                'highlight' => fn (): string => 'duplicate',
            ])
            ->download($filename);
    }

    public function hiddenDuplicates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $report = $this->longListing->hiddenDuplicatesReport(
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        return ApiResponse::success($report);
    }

    public function hiddenDuplicatesExport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $report = $this->longListing->hiddenDuplicatesReport(
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        $headers = [
            'SN.',
            'Unique Identifier',
            'Duplicate of Unique Identifier',
            'Category Code',
            'Category / Position',
            'Applicant Name',
            'Email',
            'Telephone/Mobile No',
            'ID No',
            'Hidden At',
            'Hidden By',
            'Status',
            'Received At',
        ];

        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');
        $filename = "nck_hidden_duplicates_{$stamp}.xls";
        $excelRows = [];
        foreach ($report['rows'] as $row) {
            $excelRows[] = [
                $row['serial_no'],
                $row['application_reference'],
                $row['duplicate_of_reference'],
                $row['position_code'],
                $row['position_title'],
                $row['applicant_name'],
                $row['email'],
                $row['phone'],
                $row['national_id'],
                $row['hidden_at'],
                $row['hidden_by'],
                $row['status'],
                $row['received_at'],
            ];
        }

        return (new NckReportExcel(
            'Hidden duplicates',
            ($report['total'] ?? count($excelRows)).' hidden duplicate(s)',
        ))
            ->addSheet('Hidden duplicates', $headers, $excelRows, [
                'highlight' => fn (): string => 'duplicate',
            ])
            ->download($filename);
    }

    private function listingSource(Request $request): string
    {
        return $request->query('source') === 'myjobs' ? 'myjobs' : 'mailbox';
    }

    /**
     * @return array{with: int, without: int, total: int}
     */
    private function documentCounts(bool $myJobs): array
    {
        $query = Application::query()->whereNull('duplicate_hidden_at');
        if ($myJobs) {
            $query->myJobs();
        } else {
            $query->notMyJobs();
        }
        $this->positionScope->scopeApplicationsQuery($query);

        $total = (clone $query)->count();
        $with = (clone $query)->whereHas('documents')->count();

        return [
            'with' => $with,
            'without' => max(0, $total - $with),
            'total' => $total,
        ];
    }
}
