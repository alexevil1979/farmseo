<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Services\ServiceFactory;

abstract class BaseController
{
    public function __construct(protected readonly ServiceFactory $factory)
    {
    }

    abstract protected function repository(): AbstractRepository;

    abstract protected function resourceName(): string;

    /** @return list<string> */
    abstract protected function writable(): array;

    public function index(Request $request): Response
    {
        $repo = $this->repository();
        $page = $request->page();
        $perPage = $request->perPage();
        $offset = ($page - 1) * $perPage;
        $criteria = $this->listFilters($request);
        $total = $repo->count($criteria);
        $items = $repo->getAll($criteria, $perPage, $offset, 'id DESC');

        return Response::json([
            'data' => array_map([$this, 'present'], $items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $row = $this->repository()->findById((int) $id);
        if ($row === null) {
            throw new HttpException('not_found', ucfirst($this->resourceName()) . ' not found', 404);
        }

        return Response::json(['data' => $this->present($row)]);
    }

    public function store(Request $request): Response
    {
        $data = $this->filterWritable($request->jsonObject());
        $data = $this->beforeCreate($data, $request);
        $this->validateCreate($data);
        $id = $this->repository()->create($data);
        $row = $this->repository()->findById($id);
        $this->audit($request, 'create', $id, ['input' => $this->auditSafe($data)]);

        return Response::json(['data' => $this->present($row ?? ['id' => $id])], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $entityId = (int) $id;
        $existing = $this->repository()->findById($entityId);
        if ($existing === null) {
            throw new HttpException('not_found', ucfirst($this->resourceName()) . ' not found', 404);
        }
        $data = $this->filterWritable($request->jsonObject());
        $data = $this->beforeUpdate($data, $existing, $request);
        if ($data === []) {
            throw new HttpException('validation_error', 'No updatable fields provided', 422);
        }
        $this->repository()->update($entityId, $data);
        $row = $this->repository()->findById($entityId);
        $this->audit($request, 'update', $entityId, ['input' => $this->auditSafe($data)]);

        return Response::json(['data' => $this->present($row ?? $existing)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $entityId = (int) $id;
        $existing = $this->repository()->findById($entityId);
        if ($existing === null) {
            throw new HttpException('not_found', ucfirst($this->resourceName()) . ' not found', 404);
        }
        $this->beforeDelete($existing, $request);
        $this->repository()->delete($entityId);
        $this->audit($request, 'delete', $entityId, null);

        return Response::json(['ok' => true, 'id' => $entityId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function present(array $row): array
    {
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function listFilters(Request $request): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function beforeCreate(array $data, Request $request): array
    {
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function beforeUpdate(array $data, array $existing, Request $request): array
    {
        return $data;
    }

    /** @param array<string, mixed> $existing */
    protected function beforeDelete(array $existing, Request $request): void
    {
    }

    /** @param array<string, mixed> $data */
    protected function validateCreate(array $data): void
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function filterWritable(array $input): array
    {
        $allowed = array_flip($this->writable());
        $out = [];
        foreach ($input as $k => $v) {
            if (isset($allowed[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    protected function audit(Request $request, string $action, ?int $entityId, ?array $meta): void
    {
        $user = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : null;
        if ($userId === 0) {
            $userId = null;
        }
        $this->factory->auditLogRepository()->log(
            $userId,
            $action,
            $this->resourceName(),
            $entityId,
            $request->ip(),
            $meta
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function auditSafe(array $data): array
    {
        $redact = ['password', 'password_hash', 'password_enc', 'token', 'secret'];
        foreach ($redact as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    protected function requireInt(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new HttpException('validation_error', "Field {$field} must be an integer", 422);
        }

        return (int) $value;
    }
}
