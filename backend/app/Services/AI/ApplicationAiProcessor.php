<?php

namespace App\Services\AI;

use App\Jobs\ProcessApplicationAiJob;
use App\Models\AiExtraction;
use App\Models\Applicant;
use App\Models\Application;
use App\Services\Audit\AuditLogger;
use App\Support\NairobiDate;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplicationAiProcessor
{
    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly AiSettings $settings,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function queue(Application $application, bool $force = false): ?AiExtraction
    {
        if (! $force && ! $this->settings->enabled()) {
            return null;
        }

        if (! $force) {
            $open = $application->aiExtractions()
                ->whereIn('status', [AiExtraction::STATUS_PENDING, AiExtraction::STATUS_COMPLETED])
                ->whereNull('reviewed_at')
                ->exists();
            if ($open) {
                return null;
            }
        }

        $extraction = $application->aiExtractions()->create([
            'provider' => $this->ai->providerName(),
            'status' => AiExtraction::STATUS_PENDING,
            'payload' => null,
        ]);

        $id = $extraction->id;
        $dispatch = static fn () => ProcessApplicationAiJob::dispatch($id);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }

        return $extraction;
    }

    public function run(AiExtraction $extraction): AiExtraction
    {
        $application = $extraction->application()->with(['applicant', 'mailMessage', 'position'])->first();
        if (! $application) {
            $extraction->forceFill([
                'status' => AiExtraction::STATUS_FAILED,
                'payload' => ['error' => 'Application was deleted before processing.'],
            ])->save();

            return $extraction;
        }

        $extraction->forceFill(['status' => AiExtraction::STATUS_PENDING])->save();

        try {
            $result = $this->ai->extract($this->payloadFor($application));
        } catch (Throwable $e) {
            report($e);
            $extraction->forceFill([
                'status' => AiExtraction::STATUS_FAILED,
                'provider' => $this->ai->providerName(),
                'payload' => ['error' => 'Extraction failed. Officers can retry or review the original documents.'],
            ])->save();

            return $extraction->fresh() ?? $extraction;
        }

        $confidence = isset($result['confidence']) ? (float) $result['confidence'] : null;
        $extraction->forceFill([
            'provider' => $result['provider'] ?? $this->ai->providerName(),
            'status' => AiExtraction::STATUS_COMPLETED,
            'confidence' => $confidence,
            'payload' => [
                'applicant' => $result['applicant'] ?? [],
                'position_hint' => $result['position_hint'] ?? null,
                'keywords' => $result['keywords'] ?? [],
                'summary' => $result['summary'] ?? null,
                'low_confidence' => $confidence !== null && $confidence < $this->settings->confidenceThreshold(),
                'current' => $this->currentSnapshot($application),
            ],
        ])->save();

        return $extraction->fresh() ?? $extraction;
    }

    /**
     * @param  array<string, mixed>  $edits
     * @return array{extraction: AiExtraction, applied: array<string, mixed>}
     */
    public function review(AiExtraction $extraction, string $action, ?int $userId, array $edits = []): array
    {
        if ($extraction->reviewed_at) {
            return ['extraction' => $extraction, 'applied' => []];
        }

        $extraction->loadMissing('application.applicant');
        $oldStatus = $extraction->status;

        $payload = is_array($extraction->payload) ? $extraction->payload : [];
        $suggested = is_array($payload['applicant'] ?? null) ? $payload['applicant'] : [];
        if ($action === 'edit' && $edits !== []) {
            $suggested = array_merge($suggested, $edits);
            $payload['applicant'] = $suggested;
            $payload['edited_by_reviewer'] = true;
        }

        $applied = [];
        $status = $action === 'reject' ? AiExtraction::STATUS_REJECTED : AiExtraction::STATUS_ACCEPTED;

        if ($status === AiExtraction::STATUS_ACCEPTED) {
            $applied = $this->applyEmptyApplicantFields($extraction->application?->applicant, $suggested);
        }

        $payload['review_action'] = $action === 'edit' ? 'accept' : $action;
        $extraction->forceFill([
            'status' => $status,
            'payload' => $payload,
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
        ])->save();

        $this->auditLogger->log('ai.extraction_reviewed', $extraction->application, [
            'status' => $oldStatus,
        ], [
            'action' => $payload['review_action'],
            'applied_fields' => array_keys($applied),
            'extraction_id' => $extraction->id,
        ]);

        return ['extraction' => $extraction->fresh() ?? $extraction, 'applied' => $applied];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(?AiExtraction $extraction): ?array
    {
        if (! $extraction) {
            return null;
        }

        $payload = is_array($extraction->payload) ? $extraction->payload : [];

        return [
            'id' => $extraction->id,
            'provider' => $extraction->provider,
            'status' => $extraction->status,
            'confidence' => $extraction->confidence,
            'low_confidence' => (bool) ($payload['low_confidence'] ?? false),
            'summary' => $payload['summary'] ?? null,
            'position_hint' => $payload['position_hint'] ?? null,
            'keywords' => $payload['keywords'] ?? [],
            'applicant' => $payload['applicant'] ?? [],
            'current' => $payload['current'] ?? null,
            'error' => $payload['error'] ?? null,
            'reviewed_at' => NairobiDate::iso($extraction->reviewed_at),
            'reviewed_by' => $extraction->reviewed_by,
            'created_at' => NairobiDate::iso($extraction->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Application $application): array
    {
        $message = $application->mailMessage;
        $body = trim(implode("\n", array_filter([
            $message?->body_text,
            is_array($application->profile_extraction)
                ? 'Rule-based profile: '.json_encode($application->profile_extraction, JSON_UNESCAPED_UNICODE)
                : null,
        ])));

        return [
            'subject' => $application->subject ?? $message?->subject,
            'body' => $body,
            'body_text' => $body,
            'sender_name' => $message?->sender_name ?? $application->applicant?->full_name,
            'sender_email' => $message?->sender_email ?? $application->applicant?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSnapshot(Application $application): array
    {
        $applicant = $application->applicant;

        return [
            'full_name' => $applicant?->full_name,
            'email' => $applicant?->email,
            'phone' => $applicant?->phone,
            'registration_number' => $applicant?->registration_number,
            'position' => $application->position?->title,
            'status' => $application->status,
            'screening_status' => $application->screening_status,
        ];
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<string, mixed>
     */
    private function applyEmptyApplicantFields(?Applicant $applicant, array $suggested): array
    {
        if (! $applicant) {
            return [];
        }

        $map = [
            'phone' => $suggested['phone'] ?? null,
            'registration_number' => $suggested['registration_number'] ?? null,
            'email' => $suggested['email'] ?? null,
        ];

        $updates = [];
        foreach ($map as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            if (filled($applicant->{$field})) {
                continue;
            }
            $updates[$field] = trim($value);
        }

        $name = isset($suggested['full_name']) && is_string($suggested['full_name'])
            ? trim($suggested['full_name'])
            : '';
        if ($name !== '' && $this->isPlaceholderName($applicant->full_name)) {
            $updates['full_name'] = $name;
        }

        if ($updates === []) {
            return [];
        }

        $applicant->forceFill($updates)->save();

        return $updates;
    }

    private function isPlaceholderName(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || strcasecmp($name, 'Unknown applicant') === 0) {
            return true;
        }

        return str_starts_with($name, 'Unnamed applicant');
    }
}
