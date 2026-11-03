<?php

declare(strict_types=1);

namespace Farm\Repositories;

use Farm\Support\RedisClient;
use Throwable;

final class SettingRepository extends AbstractRepository
{
    private ?RedisClient $redis = null;

    protected function table(): string
    {
        return 'settings';
    }

    protected function fillable(): array
    {
        return [
            'name',
            'value',
            'updated_by',
            'updated_at',
            'created_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['value'];
    }

    public function setRedis(?RedisClient $redis): void
    {
        $this->redis = $redis;
    }

    public function getByName(string $name): ?array
    {
        return $this->findBy(['name' => $name]);
    }

    public function set(string $name, mixed $value, ?int $updatedBy = null): int
    {
        $existing = $this->getByName($name);
        $payload = [
            'value' => $value,
            'updated_by' => $updatedBy,
        ];

        if ($existing !== null) {
            $this->update((int) $existing['id'], $payload);
            $this->mirrorToRedis($name, $value);

            return (int) $existing['id'];
        }

        $payload['name'] = $name;
        $id = $this->create($payload);
        $this->mirrorToRedis($name, $value);

        return $id;
    }

    /**
     * CONTRACT §9.23: mirror hot settings into farm:cfg:{name}.
     */
    private function mirrorToRedis(string $name, mixed $value): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->set(
                'farm:cfg:' . $name,
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                86400
            );
        } catch (Throwable) {
            // Redis optional
        }
    }
}
