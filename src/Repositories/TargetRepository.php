<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class TargetRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'targets';
    }

    protected function fillable(): array
    {
        return [
            'campaign_id',
            'url',
            'domain',
            'title',
            'is_active',
            'meta',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByCampaign(int $campaignId): array
    {
        return $this->findAllBy(['campaign_id' => $campaignId], orderBy: 'id ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByDomain(string $domain): array
    {
        return $this->findAllBy(['domain' => $domain], orderBy: 'id ASC');
    }
}
