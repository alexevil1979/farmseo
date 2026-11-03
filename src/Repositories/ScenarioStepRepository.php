<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ScenarioStepRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'scenario_steps';
    }

    protected function fillable(): array
    {
        return [
            'scenario_id',
            'step_order',
            'action',
            'params',
            'timeout_sec',
            'on_fail',
            'created_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['params'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByScenarioOrdered(int $scenarioId): array
    {
        return $this->findAllBy(
            ['scenario_id' => $scenarioId],
            orderBy: 'step_order ASC'
        );
    }
}
