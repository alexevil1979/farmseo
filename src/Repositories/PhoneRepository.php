<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class PhoneRepository extends AbstractRepository
{
    public const STATUSES = [
        'rented',
        'waiting',
        'used',
        'released',
        'cancelled',
        'expired',
    ];

    protected function table(): string
    {
        return 'phones';
    }

    protected function fillable(): array
    {
        return [
            'number',
            'provider',
            'status',
            'account_id',
            'external_id',
            'service',
            'country',
            'rented_at',
            'expires_at',
            'released_at',
            'last_code',
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
    public function findByAccountId(int $accountId): array
    {
        return $this->findAllBy(['account_id' => $accountId], orderBy: 'id DESC');
    }

    public function findActiveByAccountId(int $accountId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM `phones`
             WHERE `account_id` = ?
               AND `status` IN (\'rented\', \'waiting\', \'used\')
             ORDER BY `id` DESC
             LIMIT 1',
            [$accountId]
        );

        return $rows[0] ?? null;
    }

    public function findByExternalId(string $externalId, ?string $provider = null): ?array
    {
        if ($provider !== null) {
            return $this->findBy(['external_id' => $externalId, 'provider' => $provider]);
        }

        return $this->findBy(['external_id' => $externalId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAllBy(['status' => $status], orderBy: 'id ASC');
    }
}
