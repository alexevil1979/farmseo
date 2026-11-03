<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class BlacklistRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'blacklists';
    }

    protected function fillable(): array
    {
        return [
            'kind',
            'value',
            'reason',
            'is_active',
            'expires_at',
            'created_at',
            'updated_at',
        ];
    }

    public function findByKindValue(string $kind, string $value): ?array
    {
        return $this->findBy([
            'kind' => $kind,
            'value' => $value,
        ]);
    }

    public function isBlocked(string $kind, string $value): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT `id` FROM `blacklists`
                WHERE `kind` = :kind
                  AND `value` = :value
                  AND `is_active` = 1
                  AND (`expires_at` IS NULL OR `expires_at` > :now)
                LIMIT 1
                SQL,
            [
                ':kind' => $kind,
                ':value' => $value,
                ':now' => $now,
            ]
        );

        return $row !== null;
    }
}
