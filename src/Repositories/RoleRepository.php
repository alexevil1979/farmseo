<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class RoleRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'roles';
    }

    protected function fillable(): array
    {
        return ['name', 'description', 'created_at', 'updated_at'];
    }

    public function findByName(string $name): ?array
    {
        return $this->findBy(['name' => $name]);
    }

    public function ensure(string $name, ?string $description = null): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $data = ['name' => $name];
        if ($description !== null) {
            $data['description'] = $description;
        }

        return $this->create($data);
    }
}
