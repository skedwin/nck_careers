<?php

namespace App\Services\Applications;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\MailMessage;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ApplicationIngestionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PositionMatcher $positionMatcher,
        private readonly ApplicationProfileEnricher $profileEnricher,
        private readonly JobBoardApplicantResolver $jobBoards,
    ) {
    }

    public function createFromMailMessage(MailMessage $message, ?int $positionId = null): Application
    {
        $existing = Application::query()->where('mail_message_id', $message->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($message, $positionId): Application {
            $applicant = $this->findOrCreateApplicant($message);
            $resolvedPositionId = $positionId ?: $this->positionMatcher->resolveId($message->subject);

            $application = Application::query()->create([
                'application_reference' => $this->generateReference(),
                'applicant_id' => $applicant->id,
                'position_id' => $resolvedPositionId,
                'mail_message_id' => $message->id,
                'subject' => $message->subject,
                'status' => Application::STATUS_RECEIVED,
                'screening_status' => 'pending',
                'source' => $this->jobBoards->isJobBoardMessage($message)
                    ? strtolower($this->jobBoards->boardLabel($message))
                    : 'email',
                'received_at' => $message->received_at,
                'notes' => $this->jobBoards->isJobBoardMessage($message)
                    ? $this->jobBoardRemark($message)
                    : null,
            ]);

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => null,
                'to_status' => Application::STATUS_RECEIVED,
                'user_id' => null,
                'note' => 'Created from mailbox message.',
                'created_at' => now(),
            ]);

            $this->syncDocumentsFromMailMessage($message, $application);

            $message->forceFill(['application_created' => true])->save();

            $application->loadMissing('applicant');
            $this->profileEnricher->enrichFromMailMessage($message, $application);

            $this->auditLogger->log('application.created_from_mail', $application, null, [
                'mail_message_id' => $message->id,
                'application_reference' => $application->application_reference,
                'position_id' => $resolvedPositionId,
            ]);

            return $application->fresh(['applicant', 'position', 'documents']) ?? $application;
        });
    }

    /**
     * Append / refresh application documents from usable mail attachments
     * (downloaded files and cloud link-only rows).
     *
     * @return int Number of documents created or updated
     */
    public function syncDocumentsFromMailMessage(MailMessage $message, ?Application $application = null): int
    {
        $application ??= Application::query()->where('mail_message_id', $message->id)->first();
        if (! $application) {
            return 0;
        }

        $message->loadMissing('attachments');
        $synced = 0;

        foreach ($message->attachments as $attachment) {
            if (! $attachment->isUsableForApplication()) {
                continue;
            }

            ApplicationDocument::query()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'mail_attachment_id' => $attachment->id,
                ],
                [
                    'document_type' => $attachment->isLinkOnly()
                        ? ApplicationDocument::TYPE_CLOUD_LINK
                        : ApplicationDocument::TYPE_ATTACHMENT,
                    'original_name' => $attachment->name,
                    'disk' => $attachment->disk ?: 'private',
                    'path' => $attachment->isDownloaded() ? $attachment->path : null,
                    'external_url' => $attachment->external_url,
                    'mime_type' => $attachment->content_type,
                    'size' => $attachment->size ?? 0,
                    'sha256_hash' => $attachment->sha256_hash,
                ]
            );
            $synced++;
        }

        return $synced;
    }

    /**
     * Re-resolve positions for applications missing position_id (or all when $onlyUnassigned=false).
     *
     * @return array{updated: int, unchanged: int, unmatched: int, cleared_invalid: int}
     */
    public function reassignPositions(bool $onlyUnassigned = true, int $limit = 2000): array
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'cleared_invalid' => 0];

        // Drop links to any non NCK/REC* position rows still floating around.
        $invalidIds = \App\Models\Position::query()
            ->where('reference_code', 'not like', 'NCK/REC%')
            ->pluck('id');
        if ($invalidIds->isNotEmpty()) {
            $counts['cleared_invalid'] = Application::query()
                ->whereIn('position_id', $invalidIds)
                ->update(['position_id' => null]);
        }

        $query = Application::query()->orderBy('id');
        if ($onlyUnassigned) {
            $query->whereNull('position_id');
        }

        $applications = $query->limit(max(1, $limit))->get(['id', 'subject', 'position_id', 'mail_message_id']);

        foreach ($applications as $application) {
            $subject = $application->subject;
            if ((! is_string($subject) || trim($subject) === '') && $application->mail_message_id) {
                $subject = MailMessage::query()->whereKey($application->mail_message_id)->value('subject');
            }

            $resolvedId = $this->positionMatcher->resolveId(is_string($subject) ? $subject : null);
            if (! $resolvedId) {
                $counts['unmatched']++;
                continue;
            }

            if ((int) $application->position_id === $resolvedId) {
                $counts['unchanged']++;
                continue;
            }

            $application->forceFill(['position_id' => $resolvedId])->save();
            $counts['updated']++;
        }

        return $counts;
    }

    /**
     * @return array{created: int, skipped: int, failed: int}
     */
    public function convertPending(int $limit = 100, ?int $positionId = null): array
    {
        $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];

        $messages = MailMessage::query()
            ->where('application_created', false)
            ->where(function ($query): void {
                $query->where('has_attachments', false)
                    ->orWhereIn('attachments_status', ['downloaded', 'partial', 'none', 'failed']);
            })
            ->orderBy('received_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($messages as $message) {
            try {
                if (Application::query()->where('mail_message_id', $message->id)->exists()) {
                    $message->forceFill(['application_created' => true])->save();
                    $counts['skipped']++;

                    continue;
                }

                $this->createFromMailMessage($message, $positionId);
                $counts['created']++;
            } catch (Throwable $e) {
                report($e);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    private function findOrCreateApplicant(MailMessage $message): Applicant
    {
        // Careerjet / job boards relay many people via one noreply address —
        // never coalesce them onto a single "Careerjet" applicant.
        if ($this->jobBoards->isJobBoardMessage($message)) {
            $displayName = $this->jobBoards->resolveDisplayName($message);

            return Applicant::query()->create([
                'full_name' => $displayName,
                'email' => null,
                'meta' => [
                    'source' => strtolower($this->jobBoards->boardLabel($message)),
                    'relay_email' => strtolower(trim((string) $message->sender_email)) ?: null,
                    'relay_name' => trim((string) $message->sender_name) ?: null,
                ],
            ]);
        }

        $email = strtolower(trim((string) ($message->sender_email ?? '')));
        $name = trim((string) ($message->sender_name ?? ''));

        if ($email !== '') {
            $existing = Applicant::query()->where('email', $email)->first();
            if ($existing) {
                if ($name !== '' && blank($existing->full_name)) {
                    $existing->forceFill(['full_name' => $name])->save();
                }

                return $existing;
            }
        }

        return Applicant::query()->create([
            'full_name' => $name !== '' ? $name : ($email !== '' ? $email : 'Unknown applicant'),
            'email' => $email !== '' ? $email : null,
        ]);
    }

    /**
     * Split shared Careerjet (job-board) applicants into one person per application.
     *
     * @return array{split: int, renamed: int, remarked: int, skipped: int}
     */
    public function repairJobBoardApplicants(int $limit = 500): array
    {
        $counts = ['split' => 0, 'renamed' => 0, 'remarked' => 0, 'skipped' => 0];

        $applications = Application::query()
            ->with(['applicant', 'mailMessage'])
            ->where(function ($q): void {
                $q->whereHas('mailMessage', function ($mail): void {
                    $mail->where('sender_email', 'like', '%careerjet%')
                        ->orWhere('sender_name', 'like', '%Careerjet%');
                })->orWhereHas('applicant', function ($applicant): void {
                    $applicant->where('email', 'like', '%careerjet%')
                        ->orWhere('full_name', 'Careerjet')
                        ->orWhere('full_name', 'like', 'Unnamed applicant (via%')
                        ->orWhere('meta->source', 'careerjet');
                });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($applications as $application) {
            $message = $application->mailMessage;
            if (! $message || ! $this->jobBoards->isJobBoardMessage($message)) {
                $counts['skipped']++;
                continue;
            }

            $displayName = $this->jobBoards->resolveDisplayName($message);
            $applicant = $application->applicant;
            $remark = $this->jobBoardRemark($message);
            $shared = $applicant && (
                str_contains(strtolower((string) $applicant->email), 'careerjet')
                || strcasecmp((string) $applicant->full_name, 'Careerjet') === 0
            );

            if ($shared) {
                $siblings = Application::query()->where('applicant_id', $applicant->id)->count();
                if ($siblings > 1) {
                    $fresh = Applicant::query()->create([
                        'full_name' => $displayName,
                        'email' => null,
                        'meta' => [
                            'source' => 'careerjet',
                            'relay_email' => strtolower((string) $message->sender_email) ?: null,
                            'repaired_from_applicant_id' => $applicant->id,
                        ],
                    ]);
                    $application->forceFill([
                        'applicant_id' => $fresh->id,
                        'source' => strtolower($this->jobBoards->boardLabel($message)),
                        'notes' => $this->appendRemark($application->notes, $remark),
                    ])->save();
                    $counts['split']++;
                    $counts['remarked']++;
                } else {
                    $applicant->forceFill([
                        'full_name' => $displayName,
                        'email' => null,
                        'phone' => null,
                        'national_id' => null,
                        'gender' => null,
                        'county' => null,
                        'is_pwd' => null,
                        'pwd_details' => null,
                        'meta' => array_merge(is_array($applicant->meta) ? $applicant->meta : [], [
                            'source' => 'careerjet',
                            'relay_email' => strtolower((string) $message->sender_email) ?: null,
                        ]),
                    ])->save();
                    $application->forceFill([
                        'source' => strtolower($this->jobBoards->boardLabel($message)),
                        'notes' => $this->appendRemark($application->notes, $remark),
                    ])->save();
                    $counts['renamed']++;
                    $counts['remarked']++;
                }
            } else {
                $payload = [
                    'source' => strtolower($this->jobBoards->boardLabel($message)),
                ];
                $newNotes = $this->appendRemark($application->notes, $remark);
                if ($newNotes !== (string) $application->notes) {
                    $payload['notes'] = $newNotes;
                    $counts['remarked']++;
                }
                if ($applicant && $applicant->full_name !== $displayName
                    && (str_starts_with((string) $applicant->full_name, 'Unnamed') || strcasecmp((string) $applicant->full_name, 'Careerjet') === 0)) {
                    $applicant->forceFill(['full_name' => $displayName])->save();
                    $counts['renamed']++;
                }
                $application->forceFill($payload)->save();
            }
        }

        return $counts;
    }

    public function jobBoardRemark(MailMessage $message): string
    {
        return 'Received via '.$this->jobBoards->boardLabel($message);
    }

    private function appendRemark(?string $existing, string $remark): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $remark;
        }
        if (str_contains(strtolower($existing), strtolower($remark))) {
            return $existing;
        }

        return $existing.' | '.$remark;
    }

    private function generateReference(): string
    {
        $year = now()->timezone(\App\Support\NairobiDate::TZ)->format('Y');

        for ($i = 0; $i < 20; $i++) {
            $reference = sprintf('NCK-%s-%06d', $year, random_int(0, 999999));
            if (! Application::query()->where('application_reference', $reference)->exists()) {
                return $reference;
            }
        }

        return sprintf('NCK-%s-%s', $year, Str::upper(Str::random(6)));
    }
}
