<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\ScenarioScheduleRepository;

final class ScenarioSchedulesController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return new ScenarioScheduleRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'scenario_schedules';
    }

    protected function writable(): array
    {
        return [
            'scenario_id', 'cron_expr', 'timezone', 'is_enabled',
            'payload_override', 'next_run_at', 'last_run_at',
        ];
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (empty($data['scenario_id']) || empty($data['cron_expr'])) {
            throw new HttpException('validation_error', 'scenario_id and cron_expr are required', 422);
        }
        $data['timezone'] = (string) ($data['timezone'] ?? 'UTC');
        $data['is_enabled'] = (int) ($data['is_enabled'] ?? 1);

        return $data;
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        if ($request->query('scenario_id') !== null) {
            $f['scenario_id'] = (int) $request->query('scenario_id');
        }
        if ($request->query('is_enabled') !== null) {
            $f['is_enabled'] = (int) $request->query('is_enabled');
        }

        return $f;
    }
}
