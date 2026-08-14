<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'service' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
