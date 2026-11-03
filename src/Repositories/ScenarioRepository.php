<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ScenarioRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'scenarios';
    }

    protected function fillable(): array
    {
        return [
            'name',
            'type',
            'description',
            'version',
            'is_active',
            'defaults',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['defaults'];
    }

    public function findByNameVersion(string $name, int $version): ?array
    {
        return $this->findBy([
            'name' => $name,
            'version' => $version,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveByType(string $type): array
    {
        return $this->findAllBy(
            ['type' => $type, 'is_active' => 1],
            orderBy: 'name ASC, version DESC'
        );
    }
}
