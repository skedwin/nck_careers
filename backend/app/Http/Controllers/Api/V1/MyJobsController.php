<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MyJobs\MyJobsImportService;
use App\Services\MyJobs\MyJobsListingService;
use App\Support\ApiResponse;
use App\Support\Excel\NckReportExcel;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MyJobsController extends Controller
{
    public function __construct(
        private readonly MyJobsListingService $myJobs,
        private readonly MyJobsImportService $importer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'existence' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = $this->myJobs->paginate(
            $validated['file'] ?? null,
            $validated['existence'] ?? null,
            $validated['q'] ?? null,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
        );

        return ApiResponse::success($payload);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'overwrite' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $result = $this->importer->import(
            (bool) ($validated['overwrite'] ?? false),
            (bool) ($validated['dry_run'] ?? false),
        );

        $message = $result['dry_run']
            ? 'Dry run complete — no records written.'
            : 'MyJobs applications imported and profiles extracted.';

        return ApiResponse::success($result, $message);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'existence' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = $this->myJobs->exportRows(
            $validated['file'] ?? null,
            $validated['existence'] ?? null,
            $validated['q'] ?? null,
        );

        $headers = [
            'SN.',
            'MyJobs file',
            'Mapped position',
            'Name',
            'Email',
            'Phone',
            'In system',
            'Match',
            'Matched applicant',
            'Matched email',
            'Application references',
        ];

        $stamp = now()->timezone(NairobiDate::TZ)->format('Ymd_His');
        $filename = "nck_myjobs_{$stamp}.xls";
        $excelRows = [];
        foreach ($rows as $row) {
            $matches = $row['matches'] ?? [];
            $names = [];
            $emails = [];
            $refs = [];
            foreach ($matches as $match) {
                $names[] = $match['applicant_name'] ?? '';
                $emails[] = $match['applicant_email'] ?? '';
                foreach ($match['applications'] ?? [] as $application) {
                    $refs[] = $application['application_reference'] ?? '';
                }
            }

            $excelRows[] = [
                $row['serial_no'],
                $row['file'],
                trim(($row['mapped_position_code'] ?? '').' '.($row['mapped_position_title'] ?? '')),
                $row['name'],
                $row['email'],
                $row['phone'],
                $row['in_system'] ? 'Yes' : 'No',
                $row['match'],
                implode('; ', array_filter($names)),
                implode('; ', array_filter($emails)),
                implode('; ', array_filter($refs)),
            ];
        }

        return (new NckReportExcel(
            'MyJobs applicants',
            count($excelRows).' row(s) · existence checked by email and name',
        ))
            ->addSheet('MyJobs', $headers, $excelRows, [
                'highlight' => function (array $row): ?string {
                    $inSystem = strtolower((string) ($row[6] ?? '')) === 'yes';

                    return $inSystem ? 'found' : 'missing';
                },
            ])
            ->download($filename);
    }
}
