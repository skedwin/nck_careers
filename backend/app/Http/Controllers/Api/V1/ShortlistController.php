<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShortlistController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::query()
            ->with(['applicant:id,uuid,full_name,email', 'position:id,uuid,title,reference_code'])
            ->where(function ($builder): void {
                $builder->where('status', Application::STATUS_SHORTLISTED)
                    ->orWhere('screening_status', 'passed');
            })
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
        ]);

        return ApiResponse::success($paginator);
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

        return ApiResponse::success([
            'id' => $application->id,
            'uuid' => $application->uuid,
            'application_reference' => $application->application_reference,
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
        ], 'Application shortlisted.');
    }
}
