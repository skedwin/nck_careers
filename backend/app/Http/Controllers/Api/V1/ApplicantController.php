<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Applicant::query()->latest('id');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('registration_number', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (Applicant $applicant) => [
            'id' => $applicant->id,
            'uuid' => $applicant->uuid,
            'full_name' => $applicant->full_name,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'registration_number' => $applicant->registration_number,
            'national_id' => $applicant->national_id,
            'gender' => $applicant->gender,
            'county' => $applicant->county,
            'created_at' => NairobiDate::iso($applicant->created_at),
            'updated_at' => NairobiDate::iso($applicant->updated_at),
        ]);

        return ApiResponse::success($paginator);
    }

    public function show(Applicant $applicant): JsonResponse
    {
        $applicant->load(['applications.position:id,uuid,title,reference_code']);

        return ApiResponse::success([
            'id' => $applicant->id,
            'uuid' => $applicant->uuid,
            'full_name' => $applicant->full_name,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'registration_number' => $applicant->registration_number,
            'national_id' => $applicant->national_id,
            'gender' => $applicant->gender,
            'county' => $applicant->county,
            'meta' => $applicant->meta,
            'created_at' => NairobiDate::iso($applicant->created_at),
            'updated_at' => NairobiDate::iso($applicant->updated_at),
            'applications' => $applicant->applications->map(fn ($app) => [
                'id' => $app->id,
                'uuid' => $app->uuid,
                'application_reference' => $app->application_reference,
                'subject' => $app->subject,
                'status' => $app->status,
                'screening_status' => $app->screening_status,
                'received_at' => NairobiDate::iso($app->received_at),
                'position' => $app->position ? [
                    'id' => $app->position->id,
                    'uuid' => $app->position->uuid,
                    'title' => $app->position->title,
                    'reference_code' => $app->position->reference_code,
                ] : null,
            ])->values(),
        ]);
    }
}
