<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Repositories\AbstractRepository;

final class ProxiesController extends BaseController
{
    private const HEALTH = ['ok', 'slow', 'dead', 'unknown'];

    protected function repository(): AbstractRepository
    {
        return $this->factory->proxyRepository();
    }

    protected function resourceName(): string
    {
        return 'proxies';
    }

    protected function writable(): array
    {
        return [
            'host', 'port', 'protocol', 'username', 'password', 'password_enc',
            'country', 'health', 'latency_ms', 'is_active', 'meta',
        ];
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        if ($request->query('health') !== null) {
            $f['health'] = (string) $request->query('health');
        }
        if ($request->query('is_active') !== null) {
            $f['is_active'] = (int) $request->query('is_active');
        }

        return $f;
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        foreach (['host', 'port'] as $req) {
            if (!isset($data[$req]) || $data[$req] === '') {
                throw new HttpException('validation_error', "{$req} is required", 422);
            }
        }
        $data['protocol'] = (string) ($data['protocol'] ?? 'http');
        $data['health'] = (string) ($data['health'] ?? 'unknown');
        if (!in_array($data['health'], self::HEALTH, true)) {
            throw new HttpException('validation_error', 'Invalid proxy health', 422);
        }
        if (isset($data['password'])) {
            $data['password_enc'] = $this->factory->crypto()->encrypt((string) $data['password']);
            unset($data['password']);
        }
        $data['port'] = (int) $data['port'];

        return $data;
    }

    protected function beforeUpdate(array $data, array $existing, Request $request): array
    {
        if (isset($data['password'])) {
            $data['password_enc'] = $this->factory->crypto()->encrypt((string) $data['password']);
            unset($data['password']);
        }
        if (isset($data['health']) && !in_array((string) $data['health'], self::HEALTH, true)) {
            throw new HttpException('validation_error', 'Invalid proxy health', 422);
        }

        return $data;
    }

    protected function present(array $row): array
    {
        unset($row['password_enc']);

        return $row;
    }
}
