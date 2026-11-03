<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\ApiTokenRepository;
use DateTimeImmutable;

final class ApiTokensController extends BaseController
{
    protected function repository(): AbstractRepository
    {
        return $this->factory->apiTokenRepository();
    }

    protected function resourceName(): string
    {
        return 'api_tokens';
    }

    protected function writable(): array
    {
        return ['name', 'abilities', 'expires_at'];
    }

    protected function present(array $row): array
    {
        unset($row['token_hash']);

        return $row;
    }

    public function store(Request $request): Response
    {
        $body = $request->jsonObject();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException('validation_error', 'name is required', 422);
        }

        $user = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0 && isset($body['user_id'])) {
            $userId = (int) $body['user_id'];
        }
        if ($userId <= 0) {
            throw new HttpException('validation_error', 'Authenticated user required', 401);
        }

        $expiresAt = null;
        if (!empty($body['expires_at'])) {
            $expiresAt = new DateTimeImmutable((string) $body['expires_at']);
        }
        $abilities = isset($body['abilities']) && is_array($body['abilities']) ? $body['abilities'] : null;

        $plain = $this->factory->auth()->createApiToken($userId, $name, $abilities, $expiresAt);
        $this->audit($request, 'create', null, ['name' => $name, 'user_id' => $userId]);

        return Response::json([
            'data' => [
                'token' => $plain,
                'name' => $name,
                'user_id' => $userId,
                'abilities' => $abilities,
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s.v'),
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $tokenId = (int) $id;
        $repo = new ApiTokenRepository($this->factory->db());
        $row = $repo->findById($tokenId);
        if ($row === null) {
            throw new HttpException('not_found', 'API token not found', 404);
        }
        $this->factory->auth()->revokeApiToken($tokenId);
        $this->audit($request, 'revoke', $tokenId, []);

        return Response::json(['ok' => true]);
    }
}
