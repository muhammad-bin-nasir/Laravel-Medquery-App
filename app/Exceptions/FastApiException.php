<?php

namespace App\Exceptions;

use RuntimeException;

class FastApiException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array|string|null $details = null,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array|string|null
    {
        return $this->details;
    }
}
