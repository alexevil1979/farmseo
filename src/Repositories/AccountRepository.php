<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AccountRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'accounts';
    }

    protected function fillable(): array
    {
        return [
            'login',
            'password_enc',
            'status',
            'trust_score',
            'phone',
            'group_id',
            'tags',
            'notes',
            'proxy_id',
            'assigned_node_id',
            'profile_path',
            'last_activity',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByAssignedNodeId(int $nodeId): array
    {
        return $this->findAllBy(['assigned_node_id' => $nodeId], orderBy: 'id ASC');
    }

    protected function jsonColumns(): array
    {
        return ['tags'];
    }

    public function findByLogin(string $login): ?array
    {
        return $this->findBy(['login' => $login]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAllBy(['status' => $status], orderBy: 'id ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByProxyId(int $proxyId): array
    {
        return $this->findAllBy(['proxy_id' => $proxyId], orderBy: 'id ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByGroupId(int $groupId): array
    {
        return $this->findAllBy(['group_id' => $groupId], orderBy: 'id ASC');
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }

    public function updateTrustScore(int $id, string|float $score): bool
    {
        return $this->update($id, ['trust_score' => $score]);
    }
}
