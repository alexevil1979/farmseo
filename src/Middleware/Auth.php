<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Services\Auth as AuthService;

/**
 * Resolve Bearer api_token / JWT / session cookie into request attributes.
 */
final class Auth
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly bool $required = true,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $user = $this->auth->authenticateRequest($request);
        if ($user === null) {
            if ($this->required) {
                throw new HttpException('unauthorized', 'Authentication required', 401);
            }

            return $next($request);
        }

        $request->setAttribute('user', $user['user']);
        $request->setAttribute('role', $user['role']);
        $request->setAttribute('auth_via', $user['via']);
        $request->setAttribute('token_id', $user['token_id'] ?? null);
        $request->setAttribute('auth_context', $user);

        return $next($request);
    }
}
