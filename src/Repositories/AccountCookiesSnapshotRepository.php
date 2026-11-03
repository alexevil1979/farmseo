<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AccountCookiesSnapshotRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'account_cookies_snapshots';
    }

    protected function fillable(): array
    {
        return [
            'account_id',
            'storage_path',
            'cookie_count',
            'checksum',
            'created_at',
        ];
    }

    public function findLatestByAccount(int $accountId): ?array
    {
        $sql = <<<'SQL'
            SELECT * FROM `account_cookies_snapshots`
            WHERE `account_id` = :account_id
            ORDER BY `created_at` DESC, `id` DESC
            LIMIT 1
            SQL;

        return $this->db->fetchOne($sql, [':account_id' => $accountId]);
    }
}
