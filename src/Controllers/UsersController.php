<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\UserRepository;

final class UsersController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return new UserRepository($this->factory->db());
    }

    protected function resourceName(): string
    {
        return 'users';
    }

    protected function writable(): array
    {
        return ['role_id', 'email', 'password', 'name', 'is_active'];
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        foreach (['email', 'password', 'name', 'role_id'] as $req) {
            if (!isset($data[$req]) || $data[$req] === '') {
                throw new HttpException('validation_error', "{$req} is required", 422);
            }
        }
        $email = strtolower(trim((string) $data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('validation_error', 'Invalid email', 422);
        }
        if ((new UserRepository($this->factory->db()))->findByEmail($email) !== null) {
            throw new HttpException('conflict', 'Email already registered', 409);
        }
        $data['email'] = $email;
        $data['password_hash'] = $this->factory->auth()->hashPassword((string) $data['password']);
        unset($data['password']);
        $data['is_active'] = (int) ($data['is_active'] ?? 1);
        $data['role_id'] = (int) $data['role_id'];

        // password_hash is fillable via repo but not in writable() — inject after filter
        return $data;
    }

    public function store(Request $request): Response
    {
        $input = $request->jsonObject();
        $data = $this->filterWritable($input);
        $data = $this->beforeCreate($data, $request);
        // beforeCreate adds password_hash which filterWritable stripped
        $id = $this->repository()->create([
            'role_id' => (int) $data['role_id'],
            'email' => (string) $data['email'],
            'password_hash' => (string) $data['password_hash'],
            'name' => (string) $data['name'],
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);
        $row = $this->repository()->findById($id);
        $this->audit($request, 'create', $id, ['email' => $data['email']]);

        return Response::json(['data' => $this->present($row ?? ['id' => $id])], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $entityId = (int) $id;
        $existing = $this->repository()->findById($entityId);
        if ($existing === null) {
            throw new HttpException('not_found', 'User not found', 404);
        }
        $data = $this->filterWritable($request->jsonObject());
        $update = [];
        if (isset($data['email'])) {
            $email = strtolower(trim((string) $data['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException('validation_error', 'Invalid email', 422);
            }
            $update['email'] = $email;
        }
        if (isset($data['name'])) {
            $update['name'] = (string) $data['name'];
        }
        if (isset($data['role_id'])) {
            $update['role_id'] = (int) $data['role_id'];
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = (int) $data['is_active'];
        }
        if (isset($data['password']) && (string) $data['password'] !== '') {
            $update['password_hash'] = $this->factory->auth()->hashPassword((string) $data['password']);
        }
        if ($update === []) {
            throw new HttpException('validation_error', 'No updatable fields provided', 422);
        }
        $this->repository()->update($entityId, $update);
        $row = $this->repository()->findById($entityId);
        $this->audit($request, 'update', $entityId, ['fields' => array_keys($update)]);

        return Response::json(['data' => $this->present($row ?? $existing)]);
    }

    protected function present(array $row): array
    {
        unset($row['password_hash']);

        return $row;
    }
}
