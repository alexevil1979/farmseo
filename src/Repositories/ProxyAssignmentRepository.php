<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ProxyAssignmentRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'proxy_assignments';
    }

    protected function fillable(): array
    {
        return [
            'account_id',
            'proxy_id',
            'assigned_at',
            'released_at',
            'is_current',
        ];
    }

    public function assign(int $accountId, int $proxyId): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $this->db->execute(
            <<<'SQL'
                UPDATE `proxy_assignments`
                SET `is_current` = 0, `released_at` = :released_at
                WHERE `account_id` = :account_id AND `is_current` = 1
                SQL,
            [
                ':released_at' => $now,
                ':account_id' => $accountId,
            ]
        );

        return $this->create([
            'account_id' => $accountId,
            'proxy_id' => $proxyId,
            'assigned_at' => $now,
            'is_current' => 1,
        ]);
    }

    public function findCurrentByAccount(int $accountId): ?array
    {
        return $this->findBy([
            'account_id' => $accountId,
            'is_current' => 1,
        ]);
    }

    public function findCurrentByProxy(int $proxyId): ?array
    {
        return $this->findBy([
            'proxy_id' => $proxyId,
            'is_current' => 1,
        ]);
    }

    public function releaseCurrent(int $accountId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $stmt = $this->db->execute(
            <<<'SQL'
                UPDATE `proxy_assignments`
                SET `is_current` = 0, `released_at` = :released_at
                WHERE `account_id` = :account_id AND `is_current` = 1
                SQL,
            [
                ':released_at' => $now,
                ':account_id' => $accountId,
            ]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>> rows with proxy + account fields for dead current assignments
     */
    public function findCurrentWithDeadProxies(): array
    {
        return $this->db->fetchAll(
            <<<'SQL'
                SELECT
                    pa.`id` AS assignment_id,
                    pa.`account_id`,
                    pa.`proxy_id`,
                    pa.`assigned_at`,
                    p.`country` AS proxy_country,
                    p.`health` AS proxy_health,
                    p.`host` AS proxy_host
                FROM `proxy_assignments` pa
                INNER JOIN `proxies` p ON p.`id` = pa.`proxy_id`
                WHERE pa.`is_current` = 1
                  AND p.`health` = 'dead'
                ORDER BY pa.`id` ASC
                SQL
        );
    }

    /**
     * @return list<int>
     */
    public function currentProxyIds(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT `proxy_id` FROM `proxy_assignments`
                WHERE `is_current` = 1
                SQL
        );

        return array_map(static fn (array $r): int => (int) $r['proxy_id'], $rows);
    }
}
