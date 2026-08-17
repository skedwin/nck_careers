<?php

namespace App\Services\AI;

use App\Models\SystemSetting;

class AiSettings
{
    public function enabled(): bool
    {
        $setting = SystemSetting::query()->where('key', 'ai_enabled')->first();
        if ($setting) {
            return (bool) $setting->typedValue();
        }

        return (bool) config('nck.ai.enabled', false);
    }

    public function provider(): string
    {
        $provider = strtolower(trim((string) config('nck.ai.provider', 'mock')));

        return in_array($provider, ['mock', 'openai', 'azure_openai'], true) ? $provider : 'mock';
    }

    public function confidenceThreshold(): float
    {
        $setting = SystemSetting::query()->where('key', 'ai_confidence_threshold')->first();
        $value = $setting ? (float) $setting->typedValue() : (float) config('nck.ai.confidence_threshold', 0.7);

        return max(0.0, min(1.0, $value));
    }

    public function apiKey(): ?string
    {
        $key = trim((string) config('nck.ai.api_key', ''));

        return $key !== '' ? $key : null;
    }

    public function endpoint(): ?string
    {
        $endpoint = trim((string) config('nck.ai.endpoint', ''));

        return $endpoint !== '' ? rtrim($endpoint, '/') : null;
    }

    public function model(): string
    {
        $model = trim((string) config('nck.ai.model', 'gpt-4o-mini'));

        return $model !== '' ? $model : 'gpt-4o-mini';
    }

    public function apiVersion(): string
    {
        return trim((string) config('nck.ai.api_version', '2024-06-01')) ?: '2024-06-01';
    }

    public function timeout(): int
    {
        return max(5, (int) config('nck.ai.timeout', 45));
    }

    public function hasRemoteCredentials(): bool
    {
        if ($this->provider() === 'mock') {
            return false;
        }

        if (! $this->apiKey()) {
            return false;
        }

        return $this->provider() !== 'azure_openai' || $this->endpoint() !== null;
    }
}
