<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiExtraction;
use App\Models\Application;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\MailSyncState;
use App\Services\MicrosoftGraph\MailboxConnectionService;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private readonly MailboxConnectionService $mailboxConnection)
    {
    }

    public function __invoke(): JsonResponse
    {
        $tz = NairobiDate::TZ;
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay()->utc();
        $weekStart = $now->copy()->startOfWeek()->utc();
        $monthStart = $now->copy()->startOfMonth()->utc();

        $mailboxStatus = $this->mailboxConnection->status();
        $syncState = MailSyncState::query()->orderByDesc('id')->first();

        $byStatus = Application::query()
            ->notMyJobs()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byPosition = Application::query()
            ->notMyJobs('applications.source')
            ->leftJoin('positions', 'positions.id', '=', 'applications.position_id')
            ->select(
                'applications.position_id',
                'positions.title',
                'positions.reference_code',
                DB::raw('COUNT(applications.id) as total')
            )
            ->groupBy('applications.position_id', 'positions.title', 'positions.reference_code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'position_id' => $row->position_id,
                'title' => $row->title ?? 'Unassigned',
                'reference_code' => $row->reference_code,
                'total' => (int) $row->total,
            ]);

        $lastSuccessfulSync = $syncState?->last_successful_sync_at;

        return ApiResponse::success([
            'total_applications' => Application::query()->notMyJobs()->count(),
            'applications_today' => Application::query()->notMyJobs()->where('received_at', '>=', $todayStart)->count(),
            'applications_this_week' => Application::query()->notMyJobs()->where('received_at', '>=', $weekStart)->count(),
            'applications_this_month' => Application::query()->notMyJobs()->where('received_at', '>=', $monthStart)->count(),
            'eligible' => (int) ($byStatus[Application::STATUS_ELIGIBLE] ?? 0),
            'not_eligible' => (int) ($byStatus[Application::STATUS_NOT_ELIGIBLE] ?? 0),
            'needs_review' => (int) ($byStatus[Application::STATUS_NEEDS_REVIEW] ?? 0)
                + Application::query()->notMyJobs()->where('screening_status', 'needs_review')->count(),
            'shortlisted' => (int) ($byStatus[Application::STATUS_SHORTLISTED] ?? 0),
            'with_documents' => Application::query()->notMyJobs()->whereNull('duplicate_hidden_at')->whereHas('documents')->count(),
            'without_documents' => Application::query()->notMyJobs()->whereNull('duplicate_hidden_at')->whereDoesntHave('documents')->count(),
            'pending_ai_processing' => AiExtraction::query()
                ->whereIn('status', [AiExtraction::STATUS_PENDING, AiExtraction::STATUS_COMPLETED, AiExtraction::STATUS_FAILED])
                ->whereNull('reviewed_at')
                ->count(),
            'failed_document_processing' => MailAttachment::query()->where('download_status', 'failed')->count(),
            'mail_messages_total' => MailMessage::query()->count(),
            'mail_attachments_pending' => MailAttachment::query()->where('download_status', 'pending')->count(),
            'applications_by_position' => $byPosition,
            'applications_by_status' => $byStatus,
            'mailbox_sync' => [
                'status' => $mailboxStatus['mock_mode']
                    ? 'mock_mode'
                    : (($lastSuccessfulSync || (($mailboxStatus['last_check']['success'] ?? false)))
                        ? 'connected'
                        : 'not_verified'),
                'mailbox' => $mailboxStatus['mailbox'],
                'mock_mode' => $mailboxStatus['mock_mode'],
                'last_successful_sync_at' => NairobiDate::iso($lastSuccessfulSync),
                'is_paused' => (bool) ($syncState?->is_paused),
                'initial_sync_completed' => (bool) ($syncState?->initial_sync_completed),
            ],
        ]);
    }
}
