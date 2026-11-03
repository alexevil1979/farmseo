<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\TargetRepository;

final class TargetsController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return new TargetRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'targets';
    }

    protected function writable(): array
    {
        return ['campaign_id', 'url', 'domain', 'title', 'is_active', 'meta'];
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        if ($request->query('campaign_id') !== null) {
            $f['campaign_id'] = (int) $request->query('campaign_id');
        }
        if ($request->query('is_active') !== null) {
            $f['is_active'] = (int) $request->query('is_active');
        }

        return $f;
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (empty($data['url'])) {
            throw new HttpException('validation_error', 'url is required', 422);
        }
        if (empty($data['domain']) && is_string($data['url'])) {
            $host = parse_url((string) $data['url'], PHP_URL_HOST);
            if (is_string($host)) {
                $data['domain'] = $host;
            }
        }
        $data['is_active'] = (int) ($data['is_active'] ?? 1);

        return $data;
    }
}
