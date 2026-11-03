<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Services\Rbac as RbacService;

/**
 * Permission gate: resource + action (read|write|delete|run).
 */
final class Rbac
{
    public function __construct(
        private readonly RbacService $rbac,
        private readonly string $resource,
        private readonly string $action,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $role = (string) $request->getAttribute('role', '');
        $user = $request->getAttribute('user');
        $roleId = is_array($user) ? (int) ($user['role_id'] ?? 0) : 0;

        if (!$this->rbac->can($role, $this->resource, $this->action, $roleId > 0 ? $roleId : null)) {
            throw new HttpException(
                'forbidden',
                sprintf('Role "%s" cannot %s %s', $role !== '' ? $role : 'anonymous', $this->action, $this->resource),
                403
            );
        }

        return $next($request);
    }
}
