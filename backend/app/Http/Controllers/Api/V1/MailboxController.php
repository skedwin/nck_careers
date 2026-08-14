<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailSyncRunResource;
use App\Models\MailSyncRun;
use App\Services\MicrosoftGraph\Exceptions\GraphException;
use App\Services\MicrosoftGraph\MailboxConnectionService;
use App\Services\MicrosoftGraph\SyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    public function __construct(
        private readonly MailboxConnectionService $mailboxConnection,
        private readonly SyncService $syncService,
    ) {
    }

    public function status(): JsonResponse
    {
        return ApiResponse::success(array_merge(
            $this->mailboxConnection->status(),
            ['sync' => $this->syncService->status()]
        ));
    }

    public function testConnection(): JsonResponse
    {
        $result = $this->mailboxConnection->testConnection();

        if (! ($result['success'] ?? false)) {
            return ApiResponse::error(
                (string) ($result['message'] ?? 'Mailbox connection failed.'),
                422,
                $result
            );
        }

        return ApiResponse::success($result, 'Mailbox connection successful.');
    }

    public function startSync(Request $request): JsonResponse
    {
        try {
            $run = $this->syncService->startSync(
                trigger: 'manual',
                user: $request->user(),
                forcedType: $request->string('type')->toString() ?: null,
            );
        } catch (GraphException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(
            new MailSyncRunResource($run),
            'Full mailbox synchronization queued.'
        );
    }

    public function continueSync(Request $request): JsonResponse
    {
        try {
            $run = $this->syncService->continueSync($request->user());
        } catch (GraphException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(
            new MailSyncRunResource($run),
            'Mailbox synchronization continued from last cursor.'
        );
    }

    public function pause(Request $request): JsonResponse
    {
        $state = $this->syncService->pause($request->user());

        return ApiResponse::success([
            'is_paused' => $state->is_paused,
            'mailbox' => $state->mailbox,
        ], 'Mailbox synchronization paused.');
    }

    public function resume(Request $request): JsonResponse
    {
        $state = $this->syncService->resume($request->user());

        return ApiResponse::success([
            'is_paused' => $state->is_paused,
            'mailbox' => $state->mailbox,
        ], 'Mailbox synchronization resumed.');
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = $this->syncService->logs((int) $request->integer('per_page', 15));

        return ApiResponse::success([
            'items' => MailSyncRunResource::collection($logs->getCollection()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function retryFailed(Request $request, int $run): JsonResponse
    {
        $syncRun = MailSyncRun::query()->findOrFail($run);

        try {
            $newRun = $this->syncService->retryFailed($syncRun);
        } catch (GraphException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(
            new MailSyncRunResource($newRun),
            'Retry synchronization queued.'
        );
    }
}
