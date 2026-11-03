<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class CampaignRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'campaigns';
    }

    protected function fillable(): array
    {
        return [
            'name',
            'status',
            'scenario_id',
            'budget_tasks',
            'starts_at',
            'ends_at',
            'meta',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    public function findByName(string $name): ?array
    {
        return $this->findBy(['name' => $name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAllBy(['status' => $status], orderBy: 'id ASC');
    }
}
