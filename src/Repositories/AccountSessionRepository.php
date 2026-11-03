<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AccountSessionRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'account_sessions';
    }

    protected function fillable(): array
    {
        return [
            'account_id',
            'worker_id',
            'vnc_port',
            'novnc_url',
            'status',
            'started_at',
            'ended_at',
            'meta',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveByAccount(int $accountId): array
    {
        $sql = <<<'SQL'
            SELECT * FROM `account_sessions`
            WHERE `account_id` = :account_id
              AND `status` IN ('starting', 'active')
            ORDER BY `started_at` DESC
            SQL;

        return $this->db->fetchAll($sql, [':account_id' => $accountId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $sql = <<<'SQL'
            SELECT * FROM `account_sessions`
            WHERE `status` IN ('starting', 'active')
            ORDER BY `started_at` DESC
            SQL;

        return $this->db->fetchAll($sql);
    }

    public function endSession(int $id): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        return $this->update($id, [
            'status' => 'stopped',
            'ended_at' => $now,
        ]);
    }
}
