<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class AccountFingerprintRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'account_fingerprints';
    }

    protected function fillable(): array
    {
        return [
            'account_id',
            'user_agent',
            'platform',
            'viewport_w',
            'viewport_h',
            'timezone',
            'locale',
            'webgl_hash',
            'canvas_hash',
            'fonts_hash',
            'extra',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['extra'];
    }

    public function findByAccountId(int $accountId): ?array
    {
        return $this->findBy(['account_id' => $accountId]);
    }

    public function upsertForAccount(int $accountId, array $data): int
    {
        $data['account_id'] = $accountId;
        $existing = $this->findByAccountId($accountId);
        if ($existing !== null) {
            $this->update((int) $existing['id'], $data);

            return (int) $existing['id'];
        }

        return $this->create($data);
    }

    /**
     * Decode nested fingerprint payload from extra.fp (CONTRACT JSON column).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function extractFp(array $row): ?array
    {
        $extra = $row['extra'] ?? null;
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        if (!is_array($extra)) {
            return null;
        }
        if (isset($extra['fp']) && is_array($extra['fp'])) {
            return $extra['fp'];
        }

        return null;
    }
}
