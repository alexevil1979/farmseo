<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class TaskRunRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'task_runs';
    }

    protected function fillable(): array
    {
        return [
            'task_id',
            'attempt',
            'worker_id',
            'status',
            'started_at',
            'finished_at',
            'error',
            'metrics',
            'screenshots',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['metrics', 'screenshots'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTask(int $taskId): array
    {
        return $this->findAllBy(['task_id' => $taskId], orderBy: 'attempt ASC');
    }

    public function findByTaskAttempt(int $taskId, int $attempt): ?array
    {
        return $this->findBy([
            'task_id' => $taskId,
            'attempt' => $attempt,
        ]);
    }
}
