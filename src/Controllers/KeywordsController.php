<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\KeywordRepository;

final class KeywordsController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return new KeywordRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'keywords';
    }

    protected function writable(): array
    {
        return ['campaign_id', 'phrase', 'lang', 'geo', 'priority', 'is_active', 'meta'];
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
        if (empty($data['phrase'])) {
            throw new HttpException('validation_error', 'phrase is required', 422);
        }
        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['is_active'] = (int) ($data['is_active'] ?? 1);

        return $data;
    }
}
