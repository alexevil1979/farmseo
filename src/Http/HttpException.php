<?php

declare(strict_types=1);

namespace Farm\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $extra = [],
    ) {
        parent::__construct($message, $status);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    public function toResponse(): Response
    {
        return Response::error($this->errorCode, $this->getMessage(), $this->status, $this->extra);
    }
}
