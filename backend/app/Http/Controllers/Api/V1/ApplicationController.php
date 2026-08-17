<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Services\AI\ApplicationAiProcessor;
use App\Services\Access\PositionScopeService;
use App\Services\Applications\ApplicationIngestionService;
use App\Services\Audit\AuditLogger;
use App\Services\Reports\LongListingReportService;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    private const ALLOWED_STATUSES = [
        Application::STATUS_RECEIVED,
        Application::STATUS_UNDER_REVIEW,
        Application::STATUS_ELIGIBLE,
        Application::STATUS_NOT_ELIGIBLE,
        Application::STATUS_NEEDS_REVIEW,
        Application::STATUS_SHORTLISTED,
        Application::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly ApplicationIngestionService $ingestion,
        private readonly AuditLogger $auditLogger,
        private readonly LongListingReportService $longListing,
        private readonly PositionScopeService $positionScope,
        private readonly ApplicationAiProcessor $aiProcessor,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::query()
            ->with(['applicant:id,uuid,full_name,email,phone', 'position:id,uuid,title,reference_code,status'])
            ->latest('received_at');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('application_reference', 'like', "%{$q}%")
                    ->orWhere('subject', 'like', "%{$q}%")
                    ->orWhereHas('applicant', function ($applicant) use ($q): void {
                        $applicant->where('full_name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($screening = $request->query('screening_status')) {
            $query->where('screening_status', $screening);
        }

        if ($positionId = $request->query('position_id')) {
            $query->where('position_id', $positionId);
        }

        if ($source = trim((string) $request->query('source', ''))) {
            $query->where('source', $source);
        }

        $query->documentsFilter($request->query('documents'));
        $query->withCount('documents');

        $this->positionScope->scopeApplicationsQuery($query);

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (Application $application) => $this->serializeList($application));

        return ApiResponse::success($paginator);
    }

    public function show(Application $application): JsonResponse
    {
        $this->positionScope->assertCanAccessApplication($application);

        $application->load([
            'applicant',
            'position.criteria',
            'documents.mailAttachment:id,provider,external_url,download_status',
            'screeningResults',
            'statusHistory.user:id,name,display_name,email',
            'mailMessage',
            'latestAiExtraction',
        ]);

        return ApiResponse::success($this->serializeDetail($application));
    }

    public function updateStatus(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $newStatus = $validated['status'];

        if ($newStatus === Application::STATUS_SHORTLISTED && ! $user->can('applications.shortlist')) {
            return ApiResponse::error('Forbidden: missing applications.shortlist permission.', 403);
        }

        if ($newStatus === Application::STATUS_REJECTED && ! $user->can('applications.reject')) {
            return ApiResponse::error('Forbidden: missing applications.reject permission.', 403);
        }

        if (
            ! in_array($newStatus, [Application::STATUS_SHORTLISTED, Application::STATUS_REJECTED], true)
            && ! $user->can('applications.update')
        ) {
            return ApiResponse::error('Forbidden: missing applications.update permission.', 403);
        }

        $fromStatus = $application->status;

        if ($fromStatus === $newStatus) {
            return ApiResponse::success($this->serializeList($application->fresh(['applicant', 'position'])), 'Status unchanged.');
        }

        DB::transaction(function () use ($application, $fromStatus, $newStatus, $validated, $request): void {
            $application->forceFill(['status' => $newStatus])->save();

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'user_id' => $request->user()?->id,
                'note' => $validated['note'] ?? null,
                'created_at' => now(),
            ]);

            $this->auditLogger->log('application.status_updated', $application, [
                'status' => $fromStatus,
            ], [
                'status' => $newStatus,
                'note' => $validated['note'] ?? null,
            ], $request);
        });

        $application->load(['applicant', 'position', 'statusHistory.user:id,name,display_name,email']);

        return ApiResponse::success($this->serializeDetail($application), 'Application status updated.');
    }

    public function updateProfile(Request $request, Application $application): JsonResponse
    {
        $this->positionScope->assertCanAccessApplication($application);

        $validated = $request->validate([
            'highest_qualification' => ['nullable', 'string', Rule::in(['phd', 'masters', 'bachelors', 'higher_diploma', 'diploma', 'certificate', 'kcse'])],
            'management_course' => ['nullable', 'string', Rule::in(['Yes', 'No'])],
            'leadership_course' => ['nullable', 'string', Rule::in(['Yes', 'No'])],
            'professional_membership' => ['nullable', 'string', 'max:500'],
            'professional_qualifications' => ['nullable', 'string', 'max:1000'],
            'computer_proficiency' => ['nullable', 'string', Rule::in(['Yes', 'No'])],
            'experience_years' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'nature_of_application' => ['nullable', 'string', Rule::in(['one', 'pieces'])],
            'gender' => ['nullable', 'string', Rule::in(['Male', 'Female'])],
            'county' => ['nullable', 'string', Rule::in(\App\Support\KenyaCounties::all())],
            'is_pwd' => ['nullable', 'boolean'],
            'pwd_details' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = [
            'highest_qualification' => $application->highest_qualification,
            'professional_membership' => $application->professional_membership,
            'experience_years' => $application->experience_years,
            'gender' => $application->applicant?->gender,
            'is_pwd' => $application->applicant?->is_pwd,
        ];

        DB::transaction(function () use ($application, $validated): void {
            $appFields = [
                'highest_qualification',
                'management_course',
                'leadership_course',
                'professional_membership',
                'professional_qualifications',
                'computer_proficiency',
                'nature_of_application',
                'notes',
            ];

            $payload = [];
            foreach ($appFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }
            if (array_key_exists('experience_years', $validated)) {
                $payload['experience_years'] = $validated['experience_years'] === null
                    ? null
                    : (float) $validated['experience_years'];
            }
            if ($payload !== []) {
                $application->forceFill($payload)->save();
            }

            $applicant = $application->applicant;
            if ($applicant) {
                $applicantPayload = [];
                foreach (['gender', 'county', 'phone', 'national_id', 'pwd_details'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $applicantPayload[$field] = $validated[$field];
                    }
                }
                if (array_key_exists('is_pwd', $validated)) {
                    $applicantPayload['is_pwd'] = $validated['is_pwd'];
                }
                if ($applicantPayload !== []) {
                    $applicant->forceFill($applicantPayload)->save();
                }
            }
        });

        $application->refresh()->load([
            'applicant',
            'position.criteria',
            'documents.mailAttachment:id,provider,external_url,download_status',
            'screeningResults',
            'statusHistory.user:id,name,display_name,email',
            'mailMessage',
        ]);

        $this->auditLogger->log('application.profile_updated', $application, $before, [
            'highest_qualification' => $application->highest_qualification,
            'professional_membership' => $application->professional_membership,
            'experience_years' => $application->experience_years,
            'gender' => $application->applicant?->gender,
            'is_pwd' => $application->applicant?->is_pwd,
        ], $request);

        return ApiResponse::success($this->serializeDetail($application), 'Profile updated.');
    }

    public function hideDuplicate(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'all_related' => ['sometimes', 'boolean'],
            'application_id' => ['sometimes', 'integer', 'exists:applications,id'],
            'application_ids' => ['sometimes', 'array', 'max:500'],
            'application_ids.*' => ['integer', 'exists:applications,id'],
        ]);

        $targetId = (int) ($validated['application_id'] ?? $application->id);
        $target = $targetId === $application->id
            ? $application
            : Application::query()->findOrFail($targetId);

        $details = $this->longListing->duplicateDetailsForApplication(
            $targetId === $application->id ? $application : $target
        );
        // Prefer group context from the page's application when hiding a related row.
        $groupDetails = $this->longListing->duplicateDetailsForApplication($application) ?? $details;
        if ($groupDetails === null && ! $target->isDuplicateHidden()) {
            return ApiResponse::error('No duplicate group found for this application.', 422);
        }

        $primaryReference = $groupDetails['primary_reference'] ?? $target->duplicate_of_reference;
        $primaryId = null;
        $primaryIds = [];
        foreach ($groupDetails['related'] ?? [] as $related) {
            if (! empty($related['is_primary'])) {
                $primaryId = (int) $related['application_id'];
                $primaryIds[] = $primaryId;
                $primaryReference = $related['application_reference'];
            }
        }

        $targets = [];
        if (! empty($validated['application_ids']) && is_array($validated['application_ids'])) {
            // Bulk hide selected copies — never hide the Unique Identifier.
            foreach ($validated['application_ids'] as $id) {
                $id = (int) $id;
                if (! in_array($id, $primaryIds, true)) {
                    $targets[] = $id;
                }
            }
        } elseif (! empty($validated['all_related'])) {
            foreach ($groupDetails['related'] ?? [] as $related) {
                if (! empty($related['is_duplicate'])) {
                    $targets[] = (int) $related['application_id'];
                }
            }
        } else {
            $isPrimary = in_array($target->id, $primaryIds, true);
            if ($isPrimary) {
                return ApiResponse::error('Cannot hide the kept Unique Identifier. Hide the duplicate copies instead.', 422);
            }
            $targets[] = $target->id;
        }

        // Safety: always leave at least the Unique Identifier unhidden.
        $targets = array_values(array_diff(array_unique($targets), $primaryIds));
        if ($targets === [] && empty($validated['all_related']) && empty($validated['application_ids'])) {
            return ApiResponse::error('Nothing to hide. Keep at least one Unique Identifier.', 422);
        }

        $hidden = 0;
        foreach (array_unique($targets) as $id) {
            $row = Application::query()->find($id);
            if (! $row || $row->isDuplicateHidden()) {
                continue;
            }
            $row->forceFill([
                'duplicate_hidden_at' => now(),
                'duplicate_hidden_by' => $request->user()?->id,
                'duplicate_of_application_id' => $primaryId,
                'duplicate_of_reference' => $primaryReference,
            ])->save();
            $hidden++;
        }

        $this->auditLogger->log('application.duplicate_hidden', $application, null, [
            'hidden_count' => $hidden,
            'targets' => $targets,
            'primary_reference' => $primaryReference,
            'all_related' => (bool) ($validated['all_related'] ?? false),
        ], $request);

        $application->load([
            'applicant',
            'position.criteria',
            'documents.mailAttachment:id,provider,external_url,download_status',
            'screeningResults',
            'statusHistory.user:id,name,display_name,email',
            'mailMessage',
        ]);

        return ApiResponse::success(
            $this->serializeDetail($application),
            $hidden === 1
                ? 'Duplicate hidden from long listing.'
                : "{$hidden} duplicates hidden from long listing."
        );
    }

    public function unhideDuplicate(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['sometimes', 'integer', 'exists:applications,id'],
        ]);

        $targetId = (int) ($validated['application_id'] ?? $application->id);
        $target = $targetId === $application->id
            ? $application
            : Application::query()->findOrFail($targetId);

        if (! $target->isDuplicateHidden()) {
            return ApiResponse::error('This application is not a hidden duplicate.', 422);
        }

        $target->forceFill([
            'duplicate_hidden_at' => null,
            'duplicate_hidden_by' => null,
            'duplicate_of_application_id' => null,
            'duplicate_of_reference' => null,
        ])->save();

        $this->auditLogger->log('application.duplicate_unhidden', $target, null, [
            'application_reference' => $target->application_reference,
        ], $request);

        $application->load([
            'applicant',
            'position.criteria',
            'documents.mailAttachment:id,provider,external_url,download_status',
            'screeningResults',
            'statusHistory.user:id,name,display_name,email',
            'mailMessage',
        ]);

        return ApiResponse::success($this->serializeDetail($application), 'Duplicate restored to long listing.');
    }

    public function unhideAllDuplicates(Request $request): JsonResponse
    {
        $count = Application::query()->whereNotNull('duplicate_hidden_at')->count();

        Application::query()
            ->whereNotNull('duplicate_hidden_at')
            ->update([
                'duplicate_hidden_at' => null,
                'duplicate_hidden_by' => null,
                'duplicate_of_application_id' => null,
                'duplicate_of_reference' => null,
            ]);

        $this->auditLogger->log('application.duplicates_unhidden_all', null, null, [
            'unhidden_count' => $count,
        ], $request);

        return ApiResponse::success(
            ['unhidden' => $count],
            $count === 0
                ? 'No hidden duplicates to restore.'
                : ($count === 1
                    ? '1 duplicate restored to long listing.'
                    : "{$count} duplicates restored to long listing.")
        );
    }

    public function convertFromMailbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $result = $this->ingestion->convertPending(
            (int) ($validated['limit'] ?? 100),
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        $this->auditLogger->log('applications.converted_from_mailbox', null, null, $result, $request);

        return ApiResponse::success($result, 'Mailbox messages converted to applications.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(Application $application): array
    {
        return [
            'id' => $application->id,
            'uuid' => $application->uuid,
            'application_reference' => $application->application_reference,
            'subject' => $application->subject,
            'status' => $application->status,
            'screening_status' => $application->screening_status,
            'source' => $application->source,
            'documents_count' => (int) ($application->documents_count
                ?? $application->documents->count()),
            'received_at' => NairobiDate::iso($application->received_at),
            'created_at' => NairobiDate::iso($application->created_at),
            'updated_at' => NairobiDate::iso($application->updated_at),
            'applicant' => $application->applicant ? [
                'id' => $application->applicant->id,
                'uuid' => $application->applicant->uuid,
                'full_name' => $application->applicant->full_name,
                'email' => $application->applicant->email,
                'phone' => $application->applicant->phone,
                'national_id' => $application->applicant->national_id,
                'gender' => $application->applicant->gender,
                'county' => $application->applicant->county,
                'is_pwd' => $application->applicant->is_pwd,
                'pwd_details' => $application->applicant->pwd_details,
            ] : null,
            'position' => $application->position ? [
                'id' => $application->position->id,
                'uuid' => $application->position->uuid,
                'title' => $application->position->title,
                'reference_code' => $application->position->reference_code,
                'status' => $application->position->status,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(Application $application): array
    {
        $data = $this->serializeList($application);
        $data['notes'] = $application->notes;
        $data['mail_message_id'] = $application->mail_message_id;
        $data['position_id'] = $application->position_id;
        $data['assigned_to'] = $application->assigned_to;
        $data['profile'] = [
            'nature_of_application' => $application->nature_of_application,
            'nature_of_application_detail' => $application->nature_of_application_detail,
            'highest_qualification' => $application->highest_qualification,
            'highest_qualification_detail' => $application->highest_qualification_detail,
            'management_course' => $application->management_course,
            'leadership_course' => $application->leadership_course,
            'professional_membership' => $application->professional_membership,
            'professional_qualifications' => $application->professional_qualifications,
            'experience_summary' => $application->experience_summary,
            'experience_years' => $application->experience_years,
            'certifications_skills' => $application->certifications_skills,
            'computer_proficiency' => $application->computer_proficiency,
            'extracted_at' => NairobiDate::iso($application->profile_extracted_at),
            'sources' => data_get($application->profile_extraction, 'sources', []),
            'documents_scanned' => (int) data_get($application->profile_extraction, 'documents_scanned', 0),
            'document_sources' => data_get($application->profile_extraction, 'document_sources', []),
            'myjobs' => data_get($application->profile_extraction, 'myjobs'),
        ];

        if ($application->relationLoaded('position') && $application->position?->relationLoaded('criteria')) {
            $data['position']['criteria'] = $application->position->criteria->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'label' => $c->label,
                'description' => $c->description,
                'is_mandatory' => $c->is_mandatory,
                'weight' => $c->weight,
                'sort_order' => $c->sort_order,
            ])->values();
        }

        $data['documents'] = $application->relationLoaded('documents')
            ? $application->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'uuid' => $doc->uuid,
                'document_type' => $doc->document_type,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size' => $doc->size,
                'external_url' => $doc->external_url,
                'has_file' => filled($doc->path),
                'provider' => $doc->mailAttachment?->provider,
                'created_at' => NairobiDate::iso($doc->created_at),
            ])->values()
            : [];

        $data['screening_results'] = $application->relationLoaded('screeningResults')
            ? $application->screeningResults->map(fn ($r) => [
                'id' => $r->id,
                'criteria_code' => $r->criteria_code,
                'label' => $r->label,
                'result' => $r->result,
                'evidence' => $r->evidence,
                'scored_by' => $r->scored_by,
                'user_id' => $r->user_id,
                'updated_at' => NairobiDate::iso($r->updated_at),
            ])->values()
            : [];

        $data['status_history'] = $application->relationLoaded('statusHistory')
            ? $application->statusHistory->sortByDesc('created_at')->values()->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'note' => $h->note,
                'created_at' => NairobiDate::iso($h->created_at),
                'user' => $h->user ? [
                    'id' => $h->user->id,
                    'name' => $h->user->display_name ?: $h->user->name,
                    'email' => $h->user->email,
                ] : null,
            ])
            : [];

        $data['mail_message'] = ($application->relationLoaded('mailMessage') && $application->mailMessage)
            ? [
                'id' => $application->mailMessage->id,
                'uuid' => $application->mailMessage->uuid,
                'subject' => $application->mailMessage->subject,
                'sender_name' => $application->mailMessage->sender_name,
                'sender_email' => $application->mailMessage->sender_email,
                'received_at' => NairobiDate::iso($application->mailMessage->received_at),
                'has_attachments' => $application->mailMessage->has_attachments,
                'attachments_status' => $application->mailMessage->attachments_status,
            ]
            : null;

        $data['ai_extraction'] = $this->aiProcessor->serialize(
            $application->relationLoaded('latestAiExtraction')
                ? $application->latestAiExtraction
                : $application->aiExtractions()->latest('id')->first()
        );

        $data['duplicates'] = $this->longListing->duplicateDetailsForApplication($application);

        return $data;
    }
}
