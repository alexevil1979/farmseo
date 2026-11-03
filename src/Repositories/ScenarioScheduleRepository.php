<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ScenarioScheduleRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'scenario_schedules';
    }

    protected function fillable(): array
    {
        return [
            'scenario_id',
            'cron_expr',
            'timezone',
            'is_enabled',
            'payload_override',
            'next_run_at',
            'last_run_at',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['payload_override'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findDue(?string $now = null): array
    {
        $now ??= (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $sql = <<<'SQL'
            SELECT * FROM `scenario_schedules`
            WHERE `is_enabled` = 1
              AND `next_run_at` IS NOT NULL
              AND `next_run_at` <= :now
            ORDER BY `next_run_at` ASC
            SQL;

        return $this->db->fetchAll($sql, [':now' => $now]);
    }

    public function markRan(int $id, string $lastRunAt, ?string $nextRunAt): bool
    {
        return $this->update($id, [
            'last_run_at' => $lastRunAt,
            'next_run_at' => $nextRunAt,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findEnabled(): array
    {
        return $this->findAllBy(['is_enabled' => 1], orderBy: 'id ASC');
    }
}
