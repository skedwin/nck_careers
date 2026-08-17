<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    public function providerName(): string;

    /**
     * Extract structured facts that already appear in the payload text.
     * Must not invent qualifications or recommend hire/reject.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(array $payload): array;
}
