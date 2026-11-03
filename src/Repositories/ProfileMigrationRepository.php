<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ProfileMigrationRepository extends AbstractRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected function table(): string
    {
        return 'profile_migrations';
    }

    protected function fillable(): array
    {
        return [
            'account_id',
            'from_node_id',
            'to_node_id',
            'status',
            'archive_checksum',
            'error',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ];
    }

    public function countByStatus(string $status): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `profile_migrations` WHERE `status` = ?',
            [$status]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Latest migration row for account (any status).
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForAccount(int $accountId): ?array
    {
        return $this->db->fetchOne(
            <<<'SQL'
                SELECT * FROM `profile_migrations`
                WHERE `account_id` = ?
                ORDER BY `id` DESC
                LIMIT 1
                SQL,
            [$accountId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDoneForAccountNode(int $accountId, int $toNodeId): ?array
    {
        return $this->db->fetchOne(
            <<<'SQL'
                SELECT * FROM `profile_migrations`
                WHERE `account_id` = ? AND `to_node_id` = ? AND `status` = ?
                ORDER BY `id` DESC
                LIMIT 1
                SQL,
            [$accountId, $toNodeId, self::STATUS_DONE]
        );
    }

    /**
     * Accounts on offline/dead nodes, highest trust first, excluding those already migrating.
     *
     * @return list<array<string, mixed>>
     */
    public function findMigrationCandidates(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $limit = max(0, $limit);

        return $this->db->fetchAll(
            <<<SQL
                SELECT a.*
                FROM `accounts` a
                INNER JOIN `nodes` n ON n.`id` = a.`assigned_node_id`
                WHERE n.`status` = 'offline'
                  AND a.`assigned_node_id` IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM `profile_migrations` pm
                      WHERE pm.`account_id` = a.`id` AND pm.`status` = 'running'
                  )
                ORDER BY a.`trust_score` DESC, a.`id` ASC
                LIMIT {$limit}
                SQL
        );
    }
}

