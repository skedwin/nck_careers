<?php

namespace App\Services\AI;

class AiServiceFactory
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly MockAIService $mock,
        private readonly OpenAiCompatibleService $remote,
    ) {
    }

    public function make(): AIServiceInterface
    {
        if ($this->settings->hasRemoteCredentials()) {
            return $this->remote;
        }

        return $this->mock;
    }
}
