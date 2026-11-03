<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AlertRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'alerts';
    }

    protected function fillable(): array
    {
        return [
            'severity',
            'source',
            'message',
            'entity_type',
            'entity_id',
            'is_acked',
            'acked_by',
            'acked_at',
            'created_at',
            'meta',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findUnacked(): array
    {
        return $this->findAllBy(['is_acked' => 0], orderBy: 'created_at DESC, id DESC');
    }

    public function ack(int $id, int $userId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        return $this->update($id, [
            'is_acked' => 1,
            'acked_by' => $userId,
            'acked_at' => $now,
        ]);
    }
}
