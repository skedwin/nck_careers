<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ScreeningResult;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScreeningController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::query()
            ->with(['applicant:id,uuid,full_name,email', 'position:id,uuid,title,reference_code', 'screeningResults'])
            ->whereIn('screening_status', ['pending', 'in_progress', 'needs_review'])
            ->latest('received_at');

        if ($positionId = $request->query('position_id')) {
            $query->where('position_id', $positionId);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (Application $application) => [
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
            'screening_results' => $application->screeningResults->map(fn ($r) => [
                'criteria_code' => $r->criteria_code,
                'label' => $r->label,
                'result' => $r->result,
                'evidence' => $r->evidence,
            ])->values(),
        ]);

        return ApiResponse::success($paginator);
    }

    public function upsertResults(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.criteria_code' => ['required', 'string', 'max:64'],
            'results.*.label' => ['required', 'string', 'max:255'],
            'results.*.result' => ['required', 'string', Rule::in(['pass', 'fail', 'unknown'])],
            'results.*.evidence' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($application, $validated, $request): void {
            foreach ($validated['results'] as $row) {
                ScreeningResult::query()->updateOrCreate(
                    [
                        'application_id' => $application->id,
                        'criteria_code' => $row['criteria_code'],
                    ],
                    [
                        'label' => $row['label'],
                        'result' => $row['result'],
                        'evidence' => $row['evidence'] ?? null,
                        'scored_by' => 'user',
                        'user_id' => $request->user()?->id,
                    ]
                );
            }

            $application->forceFill([
                'screening_status' => $this->deriveScreeningStatus($application->fresh('screeningResults')),
            ])->save();
        });

        $this->auditLogger->log('screening.results_upserted', $application, null, [
            'count' => count($validated['results']),
            'screening_status' => $application->fresh()->screening_status,
        ], $request);

        $application->load(['screeningResults', 'applicant', 'position']);

        return ApiResponse::success([
            'id' => $application->id,
            'screening_status' => $application->screening_status,
            'screening_results' => $application->screeningResults->map(fn ($r) => [
                'id' => $r->id,
                'criteria_code' => $r->criteria_code,
                'label' => $r->label,
                'result' => $r->result,
                'evidence' => $r->evidence,
                'scored_by' => $r->scored_by,
                'updated_at' => NairobiDate::iso($r->updated_at),
            ])->values(),
        ], 'Screening results saved.');
    }

    public function autoScreen(Request $request, Application $application): JsonResponse
    {
        $application->load(['position.criteria', 'mailMessage', 'applicant']);

        $criteria = $application->position?->criteria ?? collect();

        if ($criteria->isEmpty()) {
            $application->forceFill(['screening_status' => 'needs_review'])->save();

            return ApiResponse::success([
                'id' => $application->id,
                'screening_status' => $application->screening_status,
                'screening_results' => [],
                'message' => 'No position criteria available for auto-screening.',
            ], 'Marked for review — no criteria.');
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $application->subject,
            $application->mailMessage?->subject,
            $application->mailMessage?->body_text,
            $application->applicant?->full_name,
            $application->applicant?->registration_number,
        ])));

        $keywordMap = [
            'nck_registration' => ['nck', 'registration', 'licence', 'license', 'registered'],
            'kcse' => ['kcse', 'mean grade', 'c+'],
            'experience_2y' => ['2 year', 'two year', 'years experience', 'experience'],
            'experience_3y' => ['3 year', 'three year', 'years experience', 'experience'],
            'bscn_degree' => ['bscn', 'bachelor', 'degree', 'nursing'],
            'degree_ict' => ['ict', 'computer', 'information technology', 'diploma'],
        ];

        DB::transaction(function () use ($application, $criteria, $haystack, $keywordMap): void {
            foreach ($criteria as $criterion) {
                $keywords = $keywordMap[$criterion->code] ?? [str_replace('_', ' ', $criterion->code)];
                $matched = false;
                $evidence = null;

                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
                        $matched = true;
                        $evidence = 'Auto-match keyword: '.$keyword;
                        break;
                    }
                }

                ScreeningResult::query()->updateOrCreate(
                    [
                        'application_id' => $application->id,
                        'criteria_code' => $criterion->code,
                    ],
                    [
                        'label' => $criterion->label,
                        'result' => $matched ? 'pass' : 'unknown',
                        'evidence' => $evidence ?? 'No keyword evidence found; requires review.',
                        'scored_by' => 'system',
                        'user_id' => null,
                    ]
                );
            }

            $application->forceFill([
                'screening_status' => $this->deriveScreeningStatus($application->fresh('screeningResults')),
            ])->save();
        });

        $this->auditLogger->log('screening.auto_screened', $application, null, [
            'screening_status' => $application->fresh()->screening_status,
        ], $request);

        $application->load('screeningResults');

        return ApiResponse::success([
            'id' => $application->id,
            'screening_status' => $application->screening_status,
            'screening_results' => $application->screeningResults->map(fn ($r) => [
                'id' => $r->id,
                'criteria_code' => $r->criteria_code,
                'label' => $r->label,
                'result' => $r->result,
                'evidence' => $r->evidence,
                'scored_by' => $r->scored_by,
                'updated_at' => NairobiDate::iso($r->updated_at),
            ])->values(),
        ], 'Auto-screening completed.');
    }

    private function deriveScreeningStatus(Application $application): string
    {
        $results = $application->screeningResults;

        if ($results->isEmpty()) {
            return 'pending';
        }

        if ($results->contains(fn ($r) => $r->result === 'fail')) {
            return 'failed';
        }

        if ($results->contains(fn ($r) => $r->result === 'unknown')) {
            return 'needs_review';
        }

        if ($results->every(fn ($r) => $r->result === 'pass')) {
            return 'passed';
        }

        return 'in_progress';
    }
}
