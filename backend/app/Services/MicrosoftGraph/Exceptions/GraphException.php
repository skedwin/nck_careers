<?php

namespace App\Services\MicrosoftGraph\Exceptions;

use RuntimeException;

class GraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $graphError = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
