<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class RunsEventRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'runs_events';
    }

    protected function fillable(): array
    {
        return [
            'task_run_id',
            'level',
            'event',
            'message',
            'data',
            'created_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTaskRun(int $taskRunId): array
    {
        return $this->findAllBy(['task_run_id' => $taskRunId], orderBy: 'created_at ASC, id ASC');
    }

    public function append(
        int $taskRunId,
        string $event,
        string $level = 'info',
        ?string $message = null,
        ?array $data = null
    ): int {
        return $this->create([
            'task_run_id' => $taskRunId,
            'event' => $event,
            'level' => $level,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
