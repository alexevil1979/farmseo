<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class PermissionRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'permissions';
    }

    protected function fillable(): array
    {
        return ['role_id', 'resource', 'action', 'created_at'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByRole(int $roleId): array
    {
        return $this->findAllBy(['role_id' => $roleId], orderBy: 'resource ASC, action ASC');
    }

    public function grant(int $roleId, string $resource, string $action): int
    {
        $inserted = $this->createIgnore([
            'role_id' => $roleId,
            'resource' => $resource,
            'action' => $action,
        ]);
        if ($inserted !== null) {
            return $inserted;
        }

        $row = $this->findBy([
            'role_id' => $roleId,
            'resource' => $resource,
            'action' => $action,
        ]);

        return (int) $this->requireRow($row, 'Permission')['id'];
    }
}
