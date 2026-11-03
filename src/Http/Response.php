<?php

declare(strict_types=1);

namespace Farm\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private mixed $body = null,
        private int $status = 200,
        private array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($data, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array{code?: string, message?: string} $error
     */
    public static function error(string $code, string $message, int $status = 400, array $extra = []): self
    {
        $payload = ['error' => array_merge(['code' => $code, 'message' => $message], $extra)];

        return self::json($payload, $status);
    }

    public static function ok(mixed $data = null, int $status = 200): self
    {
        if ($data === null) {
            return self::json(['ok' => true], $status);
        }

        return self::json($data, $status);
    }

    public static function empty(int $status = 204): self
    {
        return new self(null, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->status === 204 || $this->body === null) {
            return;
        }

        if (is_string($this->body)) {
            echo $this->body;

            return;
        }

        echo json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public function body(): mixed
    {
        return $this->body;
    }
}
