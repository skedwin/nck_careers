<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'error';
        }

        $ok = $database === 'ok';

        return ApiResponse::success([
            'service' => config('app.name'),
            'environment' => config('app.env'),
            'status' => $ok ? 'ok' : 'degraded',
            'database' => $database,
            'queue' => config('queue.default'),
            'timestamp' => now()->toIso8601String(),
        ], $ok ? '' : 'Database check failed.', $ok ? 200 : 503);
    }
}
