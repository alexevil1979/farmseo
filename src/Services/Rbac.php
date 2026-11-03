<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\PermissionRepository;
use Farm\Repositories\RoleRepository;

/**
 * Role permission matrix: admin | manager | viewer.
 *
 * Hard rules (acceptance):
 * - viewer: read only
 * - manager: read/write/run; cannot delete users
 * - admin: all
 */
final class Rbac
{
    public const ACTIONS = ['read', 'write', 'delete', 'run'];

    public const RESOURCES = [
        'accounts',
        'proxies',
        'scenarios',
        'tasks',
        'campaigns',
        'keywords',
        'targets',
        'users',
        'roles',
        'settings',
        'stats',
        'health',
    ];

    /** @var array<string, array<string, list<string>>> role => resource => actions */
    private const MATRIX = [
        'admin' => [
            '*' => ['read', 'write', 'delete', 'run'],
        ],
        'manager' => [
            'accounts' => ['read', 'write', 'delete', 'run'],
            'proxies' => ['read', 'write', 'delete', 'run'],
            'scenarios' => ['read', 'write', 'delete', 'run'],
            'tasks' => ['read', 'write', 'delete', 'run'],
            'campaigns' => ['read', 'write', 'delete', 'run'],
            'keywords' => ['read', 'write', 'delete', 'run'],
            'targets' => ['read', 'write', 'delete', 'run'],
            'users' => ['read', 'write'], // no delete
            'roles' => ['read'],
            'settings' => ['read', 'write'],
            'stats' => ['read'],
            'health' => ['read'],
        ],
        'viewer' => [
            '*' => ['read'],
        ],
    ];

    public function __construct(
        private readonly ?PermissionRepository $permissions = null,
        private readonly ?RoleRepository $roles = null,
        private readonly bool $preferMatrix = true,
    ) {
    }

    public function can(string $roleName, string $resource, string $action, ?int $roleId = null): bool
    {
        $roleName = strtolower(trim($roleName));
        $resource = strtolower(trim($resource));
        $action = strtolower(trim($action));

        if (!in_array($action, self::ACTIONS, true)) {
            return false;
        }

        // Hard denial: manager never deletes users (even if DB grants it)
        if ($roleName === 'manager' && $resource === 'users' && $action === 'delete') {
            return false;
        }

        if ($this->preferMatrix && isset(self::MATRIX[$roleName])) {
            return $this->matrixAllows($roleName, $resource, $action);
        }

        if ($roleId !== null && $this->permissions !== null) {
            foreach ($this->permissions->findByRole($roleId) as $row) {
                if ((string) $row['resource'] === $resource && (string) $row['action'] === $action) {
                    return true;
                }
            }
        }

        return $this->matrixAllows($roleName, $resource, $action);
    }

    private function matrixAllows(string $roleName, string $resource, string $action): bool
    {
        $map = self::MATRIX[$roleName] ?? null;
        if ($map === null) {
            return false;
        }

        if (isset($map['*']) && in_array($action, $map['*'], true)) {
            // viewer: * = read only — already constrained
            if ($roleName === 'viewer') {
                return $action === 'read';
            }
            if ($roleName === 'admin') {
                return true;
            }
        }

        $allowed = $map[$resource] ?? [];

        return in_array($action, $allowed, true);
    }

    /**
     * @return array<string, list<string>>
     */
    public function matrixFor(string $roleName): array
    {
        $roleName = strtolower(trim($roleName));
        if ($roleName === 'admin') {
            $out = [];
            foreach (self::RESOURCES as $res) {
                $out[$res] = self::ACTIONS;
            }

            return $out;
        }
        if ($roleName === 'viewer') {
            $out = [];
            foreach (self::RESOURCES as $res) {
                $out[$res] = ['read'];
            }

            return $out;
        }

        return self::MATRIX[$roleName] ?? [];
    }

    public function resolveRoleName(int $roleId): ?string
    {
        if ($this->roles === null) {
            return null;
        }
        $row = $this->roles->findById($roleId);

        return $row !== null ? (string) $row['name'] : null;
    }
}
