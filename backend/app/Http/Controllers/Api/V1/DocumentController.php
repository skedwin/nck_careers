<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApplicationDocument::query()
            ->with([
                'application.applicant:id,uuid,full_name,email',
                'mailAttachment:id,provider,external_url,download_status',
            ])
            ->latest('id');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('original_name', 'like', "%{$q}%")
                    ->orWhere('document_type', 'like', "%{$q}%")
                    ->orWhereHas('application', function ($app) use ($q): void {
                        $app->where('application_reference', 'like', "%{$q}%")
                            ->orWhereHas('applicant', function ($applicant) use ($q): void {
                                $applicant->where('full_name', 'like', "%{$q}%")
                                    ->orWhere('email', 'like', "%{$q}%");
                            });
                    });
            });
        }

        if ($applicationId = $request->query('application_id')) {
            $query->where('application_id', $applicationId);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (ApplicationDocument $document) => [
            'id' => $document->id,
            'uuid' => $document->uuid,
            'application_id' => $document->application_id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'path' => $document->path,
            'external_url' => $document->external_url,
            'provider' => $document->mailAttachment?->provider,
            'created_at' => NairobiDate::iso($document->created_at),
            'application' => $document->application ? [
                'id' => $document->application->id,
                'application_reference' => $document->application->application_reference,
                'applicant' => $document->application->applicant ? [
                    'id' => $document->application->applicant->id,
                    'uuid' => $document->application->applicant->uuid,
                    'full_name' => $document->application->applicant->full_name,
                    'email' => $document->application->applicant->email,
                ] : null,
            ] : null,
        ]);

        return ApiResponse::success($paginator);
    }

    public function download(ApplicationDocument $document): StreamedResponse
    {
        $disk = $document->disk ?: 'private';
        $path = $document->path;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk($disk)->download(
            $path,
            $document->original_name ?: basename($path),
            array_filter([
                'Content-Type' => $document->mime_type,
            ])
        );
    }
}
