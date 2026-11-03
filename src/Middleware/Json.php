<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;

/**
 * Enforce JSON content-type / parseable body for mutating requests.
 * Responses are always JSON (XSS surface minimized — no HTML reflection).
 */
final class Json
{
    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $method = $request->method();
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $raw = $request->rawBody();
            if ($raw !== '' && $request->json() === null) {
                throw new HttpException('invalid_json', 'Request body must be valid JSON', 400);
            }
            $ct = strtolower((string) $request->header('content-type', ''));
            if ($raw !== '' && $ct !== '' && !str_contains($ct, 'application/json')) {
                throw new HttpException('invalid_content_type', 'Content-Type must be application/json', 400);
            }
        }

        return $next($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
