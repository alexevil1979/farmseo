<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ApiTokenRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'api_tokens';
    }

    protected function fillable(): array
    {
        return [
            'user_id',
            'name',
            'token_hash',
            'abilities',
            'last_used_at',
            'expires_at',
            'revoked_at',
            'created_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['abilities'];
    }

    public function findByTokenHash(string $hash): ?array
    {
        return $this->findBy(['token_hash' => $hash]);
    }

    public function revoke(int $id): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        return $this->update($id, ['revoked_at' => $now]);
    }
}
