<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Services\Reports\LongListingReportService;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly LongListingReportService $longListing)
    {
    }

    public function summary(): JsonResponse
    {
        $tz = NairobiDate::TZ;
        $now = Carbon::now($tz);
        $weekStart = $now->copy()->startOfWeek()->utc();
        $monthStart = $now->copy()->startOfMonth()->utc();

        $byStatus = Application::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byPosition = Application::query()
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
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'position_id' => $row->position_id,
                'title' => $row->title ?? 'Unassigned',
                'reference_code' => $row->reference_code,
                'vacancies' => $row->vacancies,
                'total' => (int) $row->total,
            ]);

        $attachmentStats = MailAttachment::query()
            ->select('download_status', DB::raw('COUNT(*) as total'))
            ->groupBy('download_status')
            ->pluck('total', 'download_status');

        return ApiResponse::success([
            'counts_by_status' => $byStatus,
            'counts_by_position' => $byPosition,
            'mailbox' => [
                'messages_total' => MailMessage::query()->count(),
                'messages_pending_application' => MailMessage::query()->where('application_created', false)->count(),
                'attachments_by_status' => $attachmentStats,
                'attachments_pending' => MailAttachment::query()->where('download_status', 'pending')->count(),
                'attachments_failed' => MailAttachment::query()->where('download_status', 'failed')->count(),
                'attachments_downloaded' => MailAttachment::query()->where('download_status', 'downloaded')->count(),
            ],
            'applications_this_week' => Application::query()->where('received_at', '>=', $weekStart)->count(),
            'applications_this_month' => Application::query()->where('received_at', '>=', $monthStart)->count(),
            'generated_at' => NairobiDate::iso($now),
        ]);
    }

    public function longListing(Request $request): JsonResponse
    {
        // Landing index: one row per category (no full applicant dump).
        $report = $this->longListing->categoryIndex(
            $request->boolean('include_unassigned', true),
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = $this->longListing->paginateCategory(
            $category,
            $validated['q'] ?? null,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
            $validated['qualification'] ?? null,
            $validated['duplicates'] ?? null,
            $validated['match'] ?? null,
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
            'include_unassigned' => ['nullable', 'boolean'],
            'unassigned_only' => ['nullable', 'boolean'],
        ]);

        $headers = $this->longListing->csvHeaders();

        if (! empty($validated['category'])) {
            $category = (string) $validated['category'];
            $rows = $this->longListing->csvRowsForCategory(
                $category,
                $validated['q'] ?? null,
                $validated['qualification'] ?? null,
                $validated['duplicates'] ?? null,
                $validated['match'] ?? null,
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
        $filename = 'nck_long_listing_'.preg_replace('/[^A-Za-z0-9_\-]+/', '_', $suffix)."_{$stamp}.csv";

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = $row[$header] ?? '';
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
        $filename = "nck_hidden_duplicates_{$stamp}.csv";

        return response()->streamDownload(function () use ($headers, $report): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [
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
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
