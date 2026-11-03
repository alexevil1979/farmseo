<?php

declare(strict_types=1);

namespace Farm\Http;

/**
 * Immutable-ish HTTP request wrapper (snake_case JSON body).
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $headers lower-case keys
     * @param array<string, mixed> $query
     * @param array<string, mixed>|list<mixed>|null $json
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly mixed $json = null,
        private readonly string $rawBody = '',
        private readonly array $cookies = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value) && !is_numeric($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : $raw;
        $json = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        /** @var array<string, string> $cookies */
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $cookies[$k] = (string) $v;
            }
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            $headers,
            $_GET,
            $json,
            $raw,
            $cookies,
            $_SERVER,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null) {
            return null;
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return $m[1];
        }

        return null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function json(): mixed
    {
        return $this->json;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonObject(): array
    {
        return is_array($this->json) && !array_is_list($this->json) ? $this->json : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function ip(): string
    {
        $xff = $this->header('x-forwarded-for');
        if ($xff !== null && $xff !== '') {
            $parts = explode(',', $xff);

            return trim($parts[0]);
        }

        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', 1));
    }

    public function perPage(int $default = 50, int $max = 200): int
    {
        $n = (int) $this->query('per_page', $default);

        return max(1, min($max, $n > 0 ? $n : $default));
    }

    public function isJsonContentType(): bool
    {
        $ct = strtolower((string) $this->header('content-type', ''));

        return str_contains($ct, 'application/json') || $ct === '';
    }
}
