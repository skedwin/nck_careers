<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function redirectToMicrosoft(): RedirectResponse|JsonResponse
    {
        if (! $this->microsoftConfigured()) {
            return ApiResponse::error('Microsoft Entra ID is not configured.', 503);
        }

        return Socialite::driver('microsoft')
            ->stateless()
            ->redirect();
    }

    public function handleMicrosoftCallback(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->microsoftConfigured()) {
            return ApiResponse::error('Microsoft Entra ID is not configured.', 503);
        }

        try {
            $microsoftUser = Socialite::driver('microsoft')->stateless()->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->away($this->frontendUrl('/login?error=microsoft_auth_failed'));
        }

        $email = strtolower((string) $microsoftUser->getEmail());

        if ($email === '' || ! Str::endsWith($email, ['@nckenya.go.ke'])) {
            return redirect()->away($this->frontendUrl('/login?error=unauthorized_domain'));
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $microsoftUser->getName() ?: $email,
                'display_name' => $microsoftUser->getName(),
                'entra_id' => $microsoftUser->getId(),
                'is_active' => true,
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ]
        );

        if (! $user->is_active) {
            return redirect()->away($this->frontendUrl('/login?error=account_inactive'));
        }

        if (! $user->hasAnyRole([
            'System Administrator',
            'Recruitment Administrator',
            'Recruitment Officer',
            'Recruitment Panel Member',
            'Reviewer',
            'Read Only',
            'Auditor',
        ])) {
            // Bootstrap: first organizational sign-in becomes System Administrator
            // so mailbox sync and admin setup are available without local fallback.
            $hasSystemAdmin = User::role('System Administrator')->exists();
            $user->assignRole($hasSystemAdmin ? 'Read Only' : 'System Administrator');
        } elseif (
            $user->hasRole('Read Only')
            && ! User::role('System Administrator')->where('id', '!=', $user->id)->exists()
        ) {
            // Upgrade sole/bootstrap Read Only Entra users to System Administrator.
            $user->syncRoles(['System Administrator']);
        }

        $token = $user->createToken('microsoft-sso')->plainTextToken;

        $this->auditLogger->log('auth.microsoft_login', $user, null, ['email' => $user->email], $request);

        return redirect()->away($this->frontendUrl('/auth/callback?token='.urlencode($token)));
    }

    public function devLogin(Request $request): JsonResponse
    {
        if (! config('nck.auth_dev_login')) {
            return ApiResponse::error('Development login is disabled.', 403);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Account is inactive.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('dev-login')->plainTextToken;

        $this->auditLogger->log('auth.dev_login', $user, null, ['email' => $user->email], $request);

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('roles', 'permissions')),
        ], 'Authenticated');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()->load('roles', 'permissions')));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();

        if ($user) {
            $this->auditLogger->log('auth.logout', $user, null, null, $request);
        }

        Auth::guard('web')->logout();

        return ApiResponse::success(null, 'Logged out');
    }

    private function microsoftConfigured(): bool
    {
        return filled(config('services.microsoft.client_id'))
            && filled(config('services.microsoft.client_secret'))
            && filled(config('services.microsoft.tenant'));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('nck.frontend_url'), '/').$path;
    }
}
