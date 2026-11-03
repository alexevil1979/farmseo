<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class KeywordRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'keywords';
    }

    protected function fillable(): array
    {
        return [
            'campaign_id',
            'phrase',
            'lang',
            'geo',
            'priority',
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
        return $this->findAllBy(['campaign_id' => $campaignId], orderBy: 'priority DESC, id ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveByCampaign(int $campaignId): array
    {
        return $this->findAllBy(
            ['campaign_id' => $campaignId, 'is_active' => 1],
            orderBy: 'priority DESC, id ASC'
        );
    }
}
