<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with('user:id,name,display_name,email')
            ->latest('id');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 30));

        $paginator->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'created_at' => NairobiDate::iso($log->created_at),
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->display_name ?: $log->user->name,
                'email' => $log->user->email,
            ] : null,
        ]);

        return ApiResponse::success($paginator);
    }
}
