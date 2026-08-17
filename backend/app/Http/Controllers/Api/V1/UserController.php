<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles')->latest('id');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('display_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        $paginator->through(fn (User $user) => [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'job_title' => $user->job_title,
            'department' => $user->department,
            'is_active' => $user->is_active,
            'has_password' => filled($user->getRawOriginal('password')),
            'last_login_at' => NairobiDate::iso($user->last_login_at),
            'created_at' => NairobiDate::iso($user->created_at),
            'roles' => $user->getRoleNames()->values(),
        ]);

        return ApiResponse::success($paginator);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string'],
        ]);

        $role = Role::findByName($validated['role']);

        $oldRoles = $user->getRoleNames()->values()->all();
        $user->syncRoles([$role->name]);

        $this->auditLogger->log('user.role_updated', $user, [
            'roles' => $oldRoles,
        ], [
            'roles' => [$role->name],
        ], $request);

        return ApiResponse::success([
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
        ], 'User role updated.');
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'generate' => ['sometimes', 'boolean'],
            'password' => ['required_without:generate', 'nullable', 'string', 'min:10', 'confirmed'],
        ]);

        $plain = ! empty($validated['generate'])
            ? Str::password(16, symbols: true)
            : (string) $validated['password'];

        $user->forceFill(['password' => $plain])->save();
        $user->tokens()->delete();

        $this->auditLogger->log('user.password_updated', $user, null, [
            'email' => $user->email,
            'generated' => ! empty($validated['generate']),
        ], $request);

        return ApiResponse::success([
            'id' => $user->id,
            'email' => $user->email,
            'password' => ! empty($validated['generate']) ? $plain : null,
        ], ! empty($validated['generate'])
            ? 'Password generated. Save it now — it will not be shown again.'
            : 'Password updated. The user must sign in again.');
    }
}
