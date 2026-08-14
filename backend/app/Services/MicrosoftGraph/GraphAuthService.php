<?php

namespace App\Services\MicrosoftGraph;

use App\Services\MicrosoftGraph\Exceptions\GraphException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Acquires Microsoft Graph tokens via client credentials.
 * Tokens are cached server-side and must never be returned to React.
 */
class GraphAuthService
{
    private const CACHE_KEY = 'microsoft_graph_access_token';

    public function isConfigured(): bool
    {
        $config = config('services.microsoft_graph');

        return filled($config['tenant_id'] ?? null)
            && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null);
    }

    public function isMockMode(): bool
    {
        return (bool) config('services.microsoft_graph.mock_mode', true);
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        if ($this->isMockMode()) {
            return 'mock-graph-access-token';
        }

        if (! $this->isConfigured()) {
            throw new GraphException('Microsoft Graph credentials are not configured.');
        }

        if (! $forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $tenant = config('services.microsoft_graph.tenant_id');
        $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        $response = Http::asForm()
            ->timeout(30)
            ->post($tokenUrl, [
                'client_id' => config('services.microsoft_graph.client_id'),
                'client_secret' => config('services.microsoft_graph.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            Log::warning('microsoft_graph.token_failed', [
                'status' => $response->status(),
                // Never log secret or token body contents containing secrets.
            ]);

            throw new GraphException(
                'Failed to acquire Microsoft Graph access token.',
                $response->status(),
                $response->json()
            );
        }

        $accessToken = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if ($accessToken === '') {
            throw new GraphException('Microsoft Graph token response did not include an access token.');
        }

        // Refresh slightly before expiry.
        Cache::put(self::CACHE_KEY, $accessToken, now()->addSeconds(max(60, $expiresIn - 120)));

        return $accessToken;
    }

    public function forgetCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
