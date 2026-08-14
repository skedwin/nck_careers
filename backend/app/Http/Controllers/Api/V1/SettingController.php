<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $canManage = $request->user()?->can('settings.manage') ?? false;

        $query = SystemSetting::query()->orderBy('group')->orderBy('key');

        if (! $canManage) {
            $query->where('is_public', true);
        }

        $settings = $query->get()->mapWithKeys(fn (SystemSetting $setting) => [
            $setting->key => $setting->typedValue(),
        ]);

        return ApiResponse::success($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['present'],
        ]);

        $canManage = $request->user()?->can('settings.manage') ?? false;

        $settingQuery = SystemSetting::query()->where('key', $validated['key']);

        if (! $canManage) {
            $settingQuery->where('is_public', true);
        }

        $setting = $settingQuery->first();

        if (! $setting) {
            return ApiResponse::error('Setting not found or not editable.', 404);
        }

        $oldValue = $setting->value;
        $newValue = is_bool($validated['value'])
            ? ($validated['value'] ? 'true' : 'false')
            : (is_array($validated['value'])
                ? json_encode($validated['value'])
                : (string) $validated['value']);

        $setting->forceFill(['value' => $newValue])->save();

        $this->auditLogger->log('setting.updated', $setting, [
            'value' => $oldValue,
        ], [
            'value' => $newValue,
        ], $request);

        return ApiResponse::success([
            'key' => $setting->key,
            'value' => $setting->typedValue(),
            'group' => $setting->group,
            'is_public' => $setting->is_public,
        ], 'Setting updated.');
    }
}
