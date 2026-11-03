<?php

declare(strict_types=1);

namespace Farm\Http;

use Closure;

/**
 * Lightweight method + path router with `{param}` segments.
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, params: list<string>, handler: mixed, middleware: list<mixed>}> */
    private array $routes = [];

    /**
     * @param list<string>|string $methods
     * @param callable|array{0: class-string|object, 1: string}|Closure $handler
     * @param list<mixed> $middleware
     */
    public function add(string|array $methods, string $path, mixed $handler, array $middleware = []): self
    {
        $methods = array_map('strtoupper', (array) $methods);
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $paramNames = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];

                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path
        );
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $path,
            'regex' => $regex,
            'params' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];

        return $this;
    }

    /** @param list<mixed> $middleware */
    public function get(string $path, mixed $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<mixed> $middleware */
    public function post(string $path, mixed $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<mixed> $middleware */
    public function put(string $path, mixed $handler, array $middleware = []): self
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<mixed> $middleware */
    public function patch(string $path, mixed $handler, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<mixed> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @return array{handler: mixed, params: array<string, string>, middleware: list<mixed>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        foreach ($this->routes as $route) {
            if (!in_array($method, $route['methods'], true) && !in_array('ANY', $route['methods'], true)) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = isset($m[$name]) ? rawurldecode((string) $m[$name]) : '';
            }

            return [
                'handler' => $route['handler'],
                'params' => $params,
                'middleware' => $route['middleware'],
            ];
        }

        return null;
    }
}
