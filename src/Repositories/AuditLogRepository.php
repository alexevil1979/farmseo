<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AuditLogRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'audit_log';
    }

    protected function fillable(): array
    {
        return [
            'user_id',
            'action',
            'entity_type',
            'entity_id',
            'ip',
            'meta',
            'created_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $ip,
        ?array $meta
    ): int {
        return $this->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => $ip,
            'meta' => $meta,
        ]);
    }
}
