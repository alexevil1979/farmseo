<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;

/**
 * Require X-Internal-Token for /internal/* routes.
 */
final class InternalToken
{
    public function __construct(private readonly string $expected)
    {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $token = (string) ($request->header('x-internal-token') ?? '');
        if ($this->expected === '' || $token === '' || !hash_equals($this->expected, $token)) {
            throw new HttpException('unauthorized', 'Invalid internal token', 401);
        }

        $request->setAttribute('auth_via', 'internal');
        $request->setAttribute('role', 'admin');
        $request->setAttribute('user', null);

        return $next($request);
    }
}
