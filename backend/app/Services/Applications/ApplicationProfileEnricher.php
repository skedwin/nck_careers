<?php

namespace App\Services\Applications;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\MailMessage;

class ApplicationProfileEnricher
{
    public function __construct(
        private readonly ApplicationProfileExtractor $extractor,
        private readonly DocumentTextExtractor $documents,
    ) {
    }

    /**
     * Extract from mail body + downloaded CV/documents and persist onto applicant + application.
     *
     * @return array<string, mixed>
     */
    public function enrichFromMailMessage(MailMessage $message, ?Application $application = null, bool $overwrite = false): array
    {
        $application ??= Application::query()
            ->with(['applicant', 'documents', 'mailMessage.attachments'])
            ->where('mail_message_id', $message->id)
            ->first();

        $paths = $application
            ? ApplicationDocumentPaths::forApplication($application)
            : ApplicationDocumentPaths::forMailMessage($message);

        return $this->enrich($message, $application, $paths, $overwrite);
    }

    /**
     * @return array<string, mixed>
     */
    public function enrichFromApplication(Application $application, bool $overwrite = false): array
    {
        $application->loadMissing(['applicant', 'documents', 'mailMessage.attachments']);
        $message = $application->mailMessage;

        return $this->enrich(
            $message,
            $application,
            ApplicationDocumentPaths::forApplication($application),
            $overwrite
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function enrich(
        ?MailMessage $message,
        ?Application $application,
        ApplicationDocumentPaths $paths,
        bool $overwrite,
    ): array {
        $docResult = $this->documents->extractFromApplication($paths);
        $documentText = $docResult['text'];
        $docSources = $docResult['sources'];
        $docSkipped = $docResult['skipped'] ?? [];

        $combinedText = trim(implode("\n\n", array_filter([
            $message?->body_text,
            // Strip tags already handled in extractor for html; pass html separately too.
            $documentText !== '' ? $documentText : null,
        ])));

        $extracted = $this->extractor->extract(
            $message?->subject,
            $message?->body_html,
            $combinedText !== '' ? $combinedText : $message?->body_text
        );

        $extracted['document_sources'] = $docSources;
        $extracted['documents_scanned'] = count($docSources);
        $extracted['documents_skipped'] = $docSkipped;

        if ($application) {
            $this->applyToApplication($application, $extracted, $overwrite);
            $application->loadMissing('applicant');
            if ($application->applicant) {
                $this->applyToApplicant($application->applicant, $extracted, $overwrite);
            }
        }

        return $extracted;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    public function applyToApplication(Application $application, array $extracted, bool $overwrite = false): void
    {
        $fields = [
            'nature_of_application',
            'nature_of_application_detail',
            'management_course',
            'leadership_course',
            'professional_qualifications',
            'experience_summary',
            'experience_years',
            'certifications_skills',
            'computer_proficiency',
        ];

        $payload = [];
        $this->applyHighestQualification($application, $extracted, $overwrite, $payload);

        // REC11/REC12/REC13: professional membership not required — keep blank.
        if ($this->professionalMembershipNotRequired($application)) {
            $payload['professional_membership'] = null;
        } else {
            $membership = $extracted['professional_membership'] ?? null;
            if ($membership !== null && $membership !== ''
                && ($overwrite || blank($application->professional_membership))) {
                $payload['professional_membership'] = $membership;
            }
        }

        foreach ($fields as $field) {
            $value = $extracted[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($overwrite || blank($application->{$field})) {
                $payload[$field] = $value;
            }
        }

        $payload['profile_extraction'] = [
            'extracted' => collect($extracted)->except(['evidence', 'document_sources'])->all(),
            'evidence' => $extracted['evidence'] ?? [],
            'document_sources' => $extracted['document_sources'] ?? [],
            'documents_scanned' => $extracted['documents_scanned'] ?? 0,
            'documents_skipped' => $extracted['documents_skipped'] ?? [],
            'source_mail_message_id' => $application->mail_message_id,
            'sources' => array_values(array_filter([
                filled($application->mailMessage?->body_html) || filled($application->mailMessage?->body_text) ? 'email_body' : null,
                (($extracted['documents_scanned'] ?? 0) > 0) ? 'documents' : null,
            ])),
        ];
        $payload['profile_extracted_at'] = now();

        $application->forceFill($payload)->save();
    }

    /**
     * Fill blank qualifications, or safely downgrade when a higher degree is only ongoing.
     * Does not overwrite a correctly captured completed higher degree.
     *
     * @param  array<string, mixed>  $extracted
     * @param  array<string, mixed>  $payload
     */
    private function applyHighestQualification(
        Application $application,
        array $extracted,
        bool $overwrite,
        array &$payload
    ): void {
        $value = $extracted['highest_qualification'] ?? null;
        $detail = $extracted['highest_qualification_detail'] ?? null;
        $ongoing = $extracted['ongoing_qualifications'] ?? [];
        if (! is_array($ongoing)) {
            $ongoing = [];
        }

        $rank = [
            'kcse' => 1,
            'certificate' => 2,
            'diploma' => 3,
            'higher_diploma' => 4,
            'bachelors' => 5,
            'masters' => 6,
            'phd' => 7,
        ];

        $current = $application->highest_qualification;
        $canSet = false;

        if ($value !== null && $value !== '') {
            if ($overwrite || blank($current)) {
                $canSet = true;
            } elseif (
                in_array((string) $current, $ongoing, true)
                && ($rank[$value] ?? 0) < ($rank[(string) $current] ?? 0)
            ) {
                // Stored level is only ongoing in CV/letter → use completed lower level.
                $canSet = true;
            }
        }

        if ($canSet && $value !== null && $value !== '') {
            $payload['highest_qualification'] = $value;
        }

        if ($detail !== null && $detail !== '') {
            if ($overwrite || blank($application->highest_qualification_detail) || $canSet) {
                $payload['highest_qualification_detail'] = $detail;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    public function applyToApplicant(Applicant $applicant, array $extracted, bool $overwrite = false): void
    {
        $payload = [];

        if (! empty($extracted['phone']) && ($overwrite || blank($applicant->phone))) {
            $payload['phone'] = $extracted['phone'];
        }
        if (! empty($extracted['national_id']) && ($overwrite || blank($applicant->national_id))) {
            $payload['national_id'] = $extracted['national_id'];
        }
        if (! empty($extracted['gender']) && ($overwrite || blank($applicant->gender))) {
            $payload['gender'] = $extracted['gender'];
        }
        if (! empty($extracted['county']) && ($overwrite || blank($applicant->county))) {
            $county = \App\Support\KenyaCounties::normalize((string) $extracted['county']);
            if ($county !== null) {
                $payload['county'] = $county;
            }
        }
        if (array_key_exists('is_pwd', $extracted) && $extracted['is_pwd'] !== null
            && ($overwrite || $applicant->is_pwd === null)) {
            $payload['is_pwd'] = (bool) $extracted['is_pwd'];
        }
        if (! empty($extracted['pwd_details']) && ($overwrite || blank($applicant->pwd_details))) {
            $payload['pwd_details'] = $extracted['pwd_details'];
        }

        if ($payload !== []) {
            $applicant->forceFill($payload)->save();
        }
    }

    private function professionalMembershipNotRequired(Application $application): bool
    {
        $application->loadMissing('position:id,reference_code');
        $code = strtoupper(trim((string) ($application->position?->reference_code ?? '')));

        return in_array($code, ['NCK/REC11', 'NCK/REC12', 'NCK/REC13'], true);
    }
}
