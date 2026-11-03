<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\PermissionRepository;
use Farm\Repositories\RoleRepository;

final class RolesController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return new RoleRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'roles';
    }

    protected function writable(): array
    {
        return ['name', 'description'];
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (empty($data['name'])) {
            throw new HttpException('validation_error', 'name is required', 422);
        }
        $data['name'] = strtolower(trim((string) $data['name']));

        return $data;
    }

    protected function present(array $row): array
    {
        $perms = (new PermissionRepository($this->factory->db()))->findByRole((int) $row['id']);
        $row['permissions'] = array_map(static fn (array $p): array => [
            'resource' => $p['resource'],
            'action' => $p['action'],
        ], $perms);

        return $row;
    }

    protected function beforeDelete(array $existing, Request $request): void
    {
        if (in_array((string) $existing['name'], ['admin', 'manager', 'viewer'], true)) {
            throw new HttpException('forbidden', 'Cannot delete system role', 403);
        }
    }
}
