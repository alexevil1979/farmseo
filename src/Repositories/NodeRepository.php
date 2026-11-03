<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class NodeRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'nodes';
    }

    protected function fillable(): array
    {
        return [
            'address',
            'capacity',
            'last_hb',
            'status',
            'meta',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAllBy(['status' => $status], orderBy: 'id ASC');
    }

    /**
     * Online / draining candidates for scheduling (excludes offline).
     *
     * @return list<array<string, mixed>>
     */
    public function findSchedulable(): array
    {
        return $this->db->fetchAll(
            <<<'SQL'
                SELECT * FROM `nodes`
                WHERE `status` IN ('online', 'draining')
                ORDER BY `id` ASC
                SQL
        );
    }

    public function findByAddress(string $address): ?array
    {
        return $this->findBy(['address' => $address]);
    }

    public function updateHeartbeat(int $id, ?string $at = null): bool
    {
        return $this->update($id, [
            'last_hb' => $at ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            'status' => 'online',
        ]);
    }

    public function markOffline(int $id): bool
    {
        return $this->update($id, ['status' => 'offline']);
    }
}
