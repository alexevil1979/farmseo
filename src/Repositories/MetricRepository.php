<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class MetricRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'metrics';
    }

    protected function fillable(): array
    {
        return [
            'entity_type',
            'entity_id',
            'name',
            'value',
            'unit',
            'captured_at',
            'meta',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    public function record(
        string $entityType,
        ?int $entityId,
        string $name,
        string|float $value,
        ?string $unit = null,
        ?array $meta = null
    ): int {
        return $this->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'name' => $name,
            'value' => $value,
            'unit' => $unit,
            'meta' => $meta,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByEntity(string $entityType, ?int $entityId): array
    {
        if ($entityId === null) {
            return $this->findAllBy(
                ['entity_type' => $entityType, 'entity_id' => null],
                orderBy: 'captured_at DESC, id DESC'
            );
        }

        return $this->findAllBy(
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            orderBy: 'captured_at DESC, id DESC'
        );
    }

    /**
     * Latest row for a metric name (optionally scoped by entity_type).
     *
     * @return array<string, mixed>|null
     */
    public function latestByName(string $name, ?string $entityType = null): ?array
    {
        if ($entityType !== null) {
            return $this->db->fetchOne(
                <<<'SQL'
                    SELECT * FROM `metrics`
                    WHERE `name` = :name AND `entity_type` = :etype
                    ORDER BY `captured_at` DESC, `id` DESC
                    LIMIT 1
                    SQL,
                [':name' => $name, ':etype' => $entityType]
            );
        }

        return $this->db->fetchOne(
            <<<'SQL'
                SELECT * FROM `metrics`
                WHERE `name` = :name
                ORDER BY `captured_at` DESC, `id` DESC
                LIMIT 1
                SQL,
            [':name' => $name]
        );
    }

    /**
     * Latest system snapshot for known monitor metric names.
     *
     * @param list<string> $names
     * @return array<string, float|int|string|null>
     */
    public function latestSnapshot(array $names, string $entityType = 'system'): array
    {
        $out = [
            'captured_at' => null,
        ];
        foreach ($names as $name) {
            $row = $this->latestByName($name, $entityType);
            if ($row === null) {
                $out[$name] = null;
                continue;
            }
            $out[$name] = is_numeric($row['value']) ? (float) $row['value'] : $row['value'];
            if ($out['captured_at'] === null || (string) $row['captured_at'] > (string) $out['captured_at']) {
                $out['captured_at'] = $row['captured_at'];
            }
        }

        return $out;
    }
}
