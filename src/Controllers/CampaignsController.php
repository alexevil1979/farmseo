<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\CampaignRepository;

final class CampaignsController extends BaseController
{
    private const STATUSES = ['draft', 'active', 'paused', 'done'];

    protected function repository(): AbstractRepository
    {
        return new CampaignRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'campaigns';
    }

    protected function writable(): array
    {
        return ['name', 'status', 'scenario_id', 'budget_tasks', 'starts_at', 'ends_at', 'meta'];
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        if ($request->query('status') !== null) {
            $f['status'] = (string) $request->query('status');
        }

        return $f;
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (empty($data['name'])) {
            throw new HttpException('validation_error', 'name is required', 422);
        }
        $data['status'] = (string) ($data['status'] ?? 'draft');
        if (!in_array($data['status'], self::STATUSES, true)) {
            throw new HttpException('validation_error', 'Invalid campaign status', 422);
        }

        return $data;
    }
}
