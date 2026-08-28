<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Services\Audit\AuditLogger;
use App\Services\Shortlisting\ShortlistingExportService;
use App\Support\ApiResponse;
use App\Support\Excel\NckReportExcel;
use App\Support\NairobiDate;
use App\Support\Pdf\NckReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShortlistController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ShortlistingExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->exportService->query($request)
            ->with(['applicant:id,uuid,full_name,email', 'position:id,uuid,title,reference_code']);

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (Application $application) => $this->mapApplication($application));

        return ApiResponse::success($paginator);
    }

    public function summary(Request $request): JsonResponse
    {
        $positions = $this->exportService->positionSummary($request);
        $total = array_sum(array_column($positions, 'total'));

        return ApiResponse::success([
            'positions' => $positions,
            'total' => $total,
            'generated_at' => NairobiDate::iso(now()),
        ]);
    }

    public function grouped(Request $request): JsonResponse
    {
        $groups = $this->exportService->grouped($request);
        $total = array_sum(array_map(fn (array $group): int => $group['total'], $groups));

        return ApiResponse::success([
            'positions' => $groups,
            'total' => $total,
            'generated_at' => NairobiDate::iso(now()),
        ]);
    }

    public function shortlist(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fromStatus = $application->status;

        if ($fromStatus === Application::STATUS_SHORTLISTED) {
            return ApiResponse::success([
                'id' => $application->id,
                'status' => $application->status,
                'screening_status' => $application->screening_status,
            ], 'Application already shortlisted.');
        }

        DB::transaction(function () use ($application, $fromStatus, $validated, $request): void {
            $application->forceFill(['status' => Application::STATUS_SHORTLISTED])->save();

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => Application::STATUS_SHORTLISTED,
                'user_id' => $request->user()?->id,
                'note' => $validated['note'] ?? 'Shortlisted.',
                'created_at' => now(),
            ]);

            $this->auditLogger->log('application.shortlisted', $application, [
                'status' => $fromStatus,
            ], [
                'status' => Application::STATUS_SHORTLISTED,
                'note' => $validated['note'] ?? null,
            ], $request);
        });

        $application->load(['applicant', 'position']);

        return ApiResponse::success($this->mapApplication($application->fresh(['applicant', 'position'])), 'Application shortlisted.');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'view' => ['nullable', 'string', 'in:all,queue,shortlisted'],
        ]);

        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');

        if (! empty($validated['position_id'])) {
            $includePosition = false;
            $headers = $this->exportService->headers($includePosition);
            $rows = $this->exportService->rows($request, $includePosition);
            $filename = "nck_shortlisting_position_{$validated['position_id']}_{$stamp}.xls";

            return (new NckReportExcel('Shortlisting', $this->exportService->subtitle($request, count($rows))))
                ->addSheet('Shortlisting', $headers, $rows)
                ->download($filename);
        }

        $groups = $this->exportService->rowsByPosition($request);
        $headers = $this->exportService->headers(false);
        $total = array_sum(array_map(fn (array $group): int => $group['total'], $groups));
        $excel = new NckReportExcel('Shortlisting', $this->exportService->subtitle($request, $total));

        if ($groups === []) {
            $excel->addSheet('Shortlisting', $headers, []);
        } else {
            foreach ($groups as $group) {
                $excel->addSheet($group['sheet_name'], $headers, $group['rows']);
            }
        }

        return $excel->download("nck_shortlisting_by_position_{$stamp}.xls");
    }

    public function exportPdf(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'view' => ['nullable', 'string', 'in:all,queue,shortlisted'],
        ]);

        $includePosition = empty($validated['position_id']);
        $headers = $this->exportService->headers($includePosition);
        $rows = $this->exportService->rows($request, $includePosition);
        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');
        $suffix = ! empty($validated['position_id']) ? "position_{$validated['position_id']}" : 'by_position';
        $filename = "nck_shortlisting_{$suffix}_{$stamp}.pdf";

        return (new NckReportPdf('Shortlisting', $this->exportService->subtitle($request, count($rows))))
            ->download($filename, 'reports.table', [
                'headers' => $headers,
                'rows' => $rows,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapApplication(Application $application): array
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
