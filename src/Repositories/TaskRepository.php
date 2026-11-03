<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class TaskRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'tasks';
    }

    protected function fillable(): array
    {
        return [
            'type',
            'priority',
            'status',
            'account_id',
            'scenario_id',
            'campaign_id',
            'keyword_id',
            'target_id',
            'payload',
            'attempts',
            'max_attempts',
            'scheduled_at',
            'timeout',
            'started_at',
            'finished_at',
            'error',
            'webhook_url',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['payload'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAllBy(['status' => $status], orderBy: 'priority DESC, scheduled_at ASC, id ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPendingDue(?string $now = null): array
    {
        $now ??= (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $sql = <<<'SQL'
            SELECT * FROM `tasks`
            WHERE `status` = 'pending'
              AND (`scheduled_at` IS NULL OR `scheduled_at` <= :now)
            ORDER BY `priority` DESC, `scheduled_at` ASC, `id` ASC
            SQL;

        return $this->db->fetchAll($sql, [':now' => $now]);
    }

    public function claim(int $id, string $newStatus = 'queued'): bool
    {
        $stmt = $this->db->execute(
            <<<'SQL'
                UPDATE `tasks`
                SET `status` = :new_status
                WHERE `id` = :id AND `status` = 'pending'
                SQL,
            [
                ':new_status' => $newStatus,
                ':id' => $id,
            ]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Claim pending → queued atomically (attempts bumped by worker / RetryPolicy).
     */
    public function claimForQueue(int $id): bool
    {
        return $this->claim($id, 'queued');
    }

    /**
     * Tasks already occupying (or about to occupy) a Chrome slot.
     */
    public function countInflight(): int
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c` FROM `tasks`
                WHERE `status` IN ('queued', 'running')
                SQL
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countInflightForAccount(int $accountId): int
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c` FROM `tasks`
                WHERE `account_id` = :aid
                  AND `status` IN ('queued', 'running')
                SQL,
            [':aid' => $accountId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countInflightForProxy(int $proxyId): int
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c` FROM `tasks` t
                INNER JOIN `accounts` a ON a.`id` = t.`account_id`
                WHERE a.`proxy_id` = :pid
                  AND t.`status` IN ('queued', 'running')
                SQL,
            [':pid' => $proxyId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Revert queued → pending (admission defer / Redis push failure).
     *
     * @param array<string, mixed> $extra
     */
    public function releaseToPending(int $id, array $extra = []): bool
    {
        $data = array_merge(['status' => 'pending'], $extra);
        $filtered = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $this->fillable(), true)) {
                $filtered[$k] = $v;
            }
        }
        if ($filtered === []) {
            return false;
        }
        // Only release if still queued (or already pending for idempotent defer).
        $sets = [];
        $params = [':id' => $id];
        foreach ($filtered as $col => $val) {
            $sets[] = sprintf('`%s` = :%s', $col, $col);
            $params[':' . $col] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
        }
        $sql = sprintf(
            'UPDATE `tasks` SET %s WHERE `id` = :id AND `status` IN (\'queued\', \'pending\')',
            implode(', ', $sets)
        );
        $stmt = $this->db->execute($sql, $params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRetryingDue(?string $now = null): array
    {
        $now ??= (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $sql = <<<'SQL'
            SELECT * FROM `tasks`
            WHERE `status` = 'retrying'
              AND (`scheduled_at` IS NULL OR `scheduled_at` <= :now)
            ORDER BY `priority` DESC, `scheduled_at` ASC, `id` ASC
            SQL;

        return $this->db->fetchAll($sql, [':now' => $now]);
    }

    /**
     * Running tasks whose started_at is older than timeout (or absolute age).
     *
     * @return list<array<string, mixed>>
     */
    public function findStaleRunning(?string $olderThan = null): array
    {
        $olderThan ??= (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s.v');

        $sql = <<<'SQL'
            SELECT * FROM `tasks`
            WHERE `status` = 'running'
              AND (
                    (`started_at` IS NOT NULL AND `started_at` <= :older)
                 OR (`started_at` IS NULL AND `updated_at` <= :older2)
              )
            ORDER BY `started_at` ASC, `id` ASC
            SQL;

        return $this->db->fetchAll($sql, [
            ':older' => $olderThan,
            ':older2' => $olderThan,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `status`, COUNT(*) AS `c` FROM `tasks` GROUP BY `status`'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function accountIdsWithStatus(string $status): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT `account_id` FROM `tasks` WHERE `status` = :s AND `account_id` IS NOT NULL',
            [':s' => $status]
        );

        return array_map(static fn (array $r): int => (int) $r['account_id'], $rows);
    }

    public function markFinished(int $id, string $status, ?string $error = null): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        return $this->update($id, [
            'status' => $status,
            'finished_at' => $now,
            'error' => $error,
        ]);
    }
}
