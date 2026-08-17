<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Access\PositionScopeService;
use App\Services\AI\ApplicationAiProcessor;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiExtractionController extends Controller
{
    public function __construct(
        private readonly ApplicationAiProcessor $processor,
        private readonly PositionScopeService $positionScope,
    ) {
    }

    public function process(Request $request, Application $application): JsonResponse
    {
        $this->positionScope->assertCanAccessApplication($application);

        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        $extraction = $this->processor->queue($application, (bool) ($validated['force'] ?? true));
        if (! $extraction) {
            $latest = $application->aiExtractions()->latest('id')->first();

            return ApiResponse::success([
                'ai_extraction' => $this->processor->serialize($latest),
            ], 'No new extraction queued.');
        }

        $extraction = $extraction->fresh() ?? $extraction;

        return ApiResponse::success([
            'ai_extraction' => $this->processor->serialize($extraction),
        ], $extraction->status === 'pending'
            ? 'System assessment queued.'
            : 'System assessment completed.');
    }

    public function review(Request $request, Application $application): JsonResponse
    {
        $this->positionScope->assertCanAccessApplication($application);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['accept', 'reject', 'edit'])],
            'applicant' => ['nullable', 'array'],
            'applicant.full_name' => ['nullable', 'string', 'max:255'],
            'applicant.email' => ['nullable', 'email', 'max:255'],
            'applicant.phone' => ['nullable', 'string', 'max:64'],
            'applicant.registration_number' => ['nullable', 'string', 'max:64'],
        ]);

        $extraction = $application->aiExtractions()->latest('id')->first();
        if (! $extraction) {
            return ApiResponse::error('No system assessment to review.', 404);
        }

        $result = $this->processor->review(
            $extraction,
            $validated['action'],
            $request->user()?->id,
            $validated['applicant'] ?? [],
        );

        $application->load(['applicant', 'aiExtractions']);

        return ApiResponse::success([
            'ai_extraction' => $this->processor->serialize($result['extraction']),
            'applied' => $result['applied'],
            'applicant' => [
                'id' => $application->applicant?->id,
                'full_name' => $application->applicant?->full_name,
                'email' => $application->applicant?->email,
                'phone' => $application->applicant?->phone,
                'registration_number' => $application->applicant?->registration_number,
            ],
        ], $validated['action'] === 'reject'
            ? 'System assessment rejected. Original records were not changed.'
            : 'System assessment recorded. Empty applicant fields were filled where suggested.');
    }
}
