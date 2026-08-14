<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(array $payload): array;
}
