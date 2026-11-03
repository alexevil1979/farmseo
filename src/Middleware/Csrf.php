<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Services\Auth as AuthService;

/**
 * CSRF for cookie/session auth. Bearer / internal tokens skip this check.
 */
final class Csrf
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $via = (string) $request->getAttribute('auth_via', '');
        if ($via !== 'session') {
            return $next($request);
        }

        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $token = $request->header('x-csrf-token')
            ?? $request->header('x-xsrf-token')
            ?? (is_array($request->json()) && !array_is_list($request->json())
                ? (string) ($request->jsonObject()['_csrf'] ?? '')
                : '');

        if ($token === '' || !$this->auth->validateCsrfToken($token)) {
            throw new HttpException('csrf_mismatch', 'Invalid or missing CSRF token', 419);
        }

        return $next($request);
    }
}
