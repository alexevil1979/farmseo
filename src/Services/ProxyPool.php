<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Db\Connection;
use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ProxyAssignmentRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Support\LockInterface;
use RuntimeException;

/**
 * Sticky proxy pool: 1 account ↔ 1 permanent proxy (via proxy_assignments).
 * Rotation only when current proxy is dead; same geo preferred.
 */
final class ProxyPool
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProxyRepository $proxies,
        private readonly ProxyAssignmentRepository $assignments,
        private readonly AccountRepository $accounts,
        private readonly LockInterface $locks,
        private readonly GeoMapper $geoMapper = new GeoMapper(),
        private readonly ?AccountFingerprintRepository $fingerprints = null,
    ) {
    }

    /**
     * Assign a sticky proxy to an account.
     * If a healthy current assignment exists — keep it (sticky).
     *
     * @param array{country?: string|null, force?: bool, health_allow?: list<string>} $options
     * @return array{assignment_id: int, proxy_id: int, account_id: int, sticky: bool, proxy: array<string, mixed>}
     */
    public function assign(int $accountId, array $options = []): array
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException("Account #{$accountId} not found");
        }

        $force = (bool) ($options['force'] ?? false);
        $country = isset($options['country']) && $options['country'] !== ''
            ? strtoupper(substr((string) $options['country'], 0, 2))
            : null;

        $current = $this->assignments->findCurrentByAccount($accountId);
        if ($current !== null && !$force) {
            $proxy = $this->proxies->findById((int) $current['proxy_id']);
            if ($proxy !== null && ($proxy['health'] ?? '') !== 'dead' && (int) ($proxy['is_active'] ?? 0) === 1) {
                // Sticky: keep current live proxy; sync accounts.proxy_id if drifted
                if ((int) ($account['proxy_id'] ?? 0) !== (int) $proxy['id']) {
                    $this->accounts->update($accountId, ['proxy_id' => (int) $proxy['id']]);
                }

                return [
                    'assignment_id' => (int) $current['id'],
                    'proxy_id' => (int) $proxy['id'],
                    'account_id' => $accountId,
                    'sticky' => true,
                    'proxy' => $proxy,
                ];
            }
            // Prefer same geo when replacing dead
            if ($country === null && $proxy !== null && !empty($proxy['country'])) {
                $country = strtoupper(substr((string) $proxy['country'], 0, 2));
            }
        }

        $healthAllow = $options['health_allow'] ?? ['ok', 'slow', 'unknown'];
        $candidates = $this->proxies->findAvailable($country, $healthAllow);
        if ($candidates === []) {
            throw new RuntimeException(
                $country !== null
                    ? "No available proxy for country {$country}"
                    : 'No available proxy in pool'
            );
        }

        $lastError = null;
        foreach ($candidates as $candidate) {
            $proxyId = (int) $candidate['id'];
            $token = $this->locks->tryAcquire($this->locks->proxyKey($proxyId), 15);
            if ($token === null) {
                continue;
            }

            try {
                // Re-check free under lock
                $taken = $this->assignments->findCurrentByProxy($proxyId);
                if ($taken !== null && (int) $taken['account_id'] !== $accountId) {
                    continue;
                }

                return $this->db->pdo()->inTransaction()
                    ? $this->bindAccountToProxy($accountId, $proxyId, $candidate)
                    : $this->bindInTransaction($accountId, $proxyId, $candidate);
            } catch (\Throwable $e) {
                $lastError = $e;
            } finally {
                $this->locks->release($this->locks->proxyKey($proxyId), $token);
            }
        }

        throw new RuntimeException(
            'Failed to assign proxy: ' . ($lastError?->getMessage() ?? 'all candidates locked or taken')
        );
    }

    /**
     * Release current proxy from account (clears accounts.proxy_id).
     */
    public function release(int $accountId): bool
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException("Account #{$accountId} not found");
        }

        $current = $this->assignments->findCurrentByAccount($accountId);
        $proxyId = $current !== null ? (int) $current['proxy_id'] : (int) ($account['proxy_id'] ?? 0);

        $run = function () use ($accountId): bool {
            $released = $this->assignments->releaseCurrent($accountId);
            $this->accounts->update($accountId, ['proxy_id' => null]);

            return $released;
        };

        if ($proxyId > 0) {
            return $this->locks->withLock(
                $this->locks->proxyKey($proxyId),
                function () use ($run) {
                    if ($this->db->inTransaction()) {
                        return $run();
                    }
                    $this->db->beginTransaction();
                    try {
                        $r = $run();
                        $this->db->commit();

                        return $r;
                    } catch (\Throwable $e) {
                        $this->db->rollBack();
                        throw $e;
                    }
                },
                15
            );
        }

        return $run();
    }

    /**
     * Replace dead proxies for all accounts with a live proxy from the same geo.
     *
     * @return list<array{account_id: int, old_proxy_id: int, new_proxy_id: int|null, ok: bool, error: string|null}>
     */
    public function rotateDead(): array
    {
        $rows = $this->assignments->findCurrentWithDeadProxies();
        $results = [];

        foreach ($rows as $row) {
            $accountId = (int) $row['account_id'];
            $oldProxyId = (int) $row['proxy_id'];
            $country = isset($row['proxy_country']) && $row['proxy_country'] !== ''
                ? (string) $row['proxy_country']
                : null;

            try {
                $assigned = $this->assign($accountId, [
                    'force' => true,
                    'country' => $country,
                    'health_allow' => ['ok', 'slow'],
                ]);
                $results[] = [
                    'account_id' => $accountId,
                    'old_proxy_id' => $oldProxyId,
                    'new_proxy_id' => (int) $assigned['proxy_id'],
                    'ok' => true,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $accountId,
                    'old_proxy_id' => $oldProxyId,
                    'new_proxy_id' => null,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function currentProxy(int $accountId): ?array
    {
        $assignment = $this->assignments->findCurrentByAccount($accountId);
        if ($assignment === null) {
            $account = $this->accounts->findById($accountId);
            if ($account === null || empty($account['proxy_id'])) {
                return null;
            }

            return $this->proxies->findById((int) $account['proxy_id']);
        }

        return $this->proxies->findById((int) $assignment['proxy_id']);
    }

    /**
     * Ensure account has at most one current assignment (repair drift).
     */
    public function assertSingleProxy(int $accountId): void
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT `id` FROM `proxy_assignments`
                WHERE `account_id` = :account_id AND `is_current` = 1
                ORDER BY `id` DESC
                SQL,
            [':account_id' => $accountId]
        );
        if (count($rows) <= 1) {
            return;
        }
        // Keep newest, release the rest
        array_shift($rows);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        foreach ($rows as $row) {
            $this->assignments->update((int) $row['id'], [
                'is_current' => 0,
                'released_at' => $now,
            ]);
        }
    }

    /**
     * Import lines host:port:user:pass (or host:port).
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importLines(iterable $lines, string $protocol = 'http', ?string $defaultCountry = null): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $crypto = new \Farm\Support\Crypto();

        $n = 0;
        foreach ($lines as $line) {
            $n++;
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Strip CSV quotes / BOM
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            $line = trim($line, " \t\"'");

            $parts = preg_split('/[:;,\t]/', $line) ?: [];
            $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
            if (count($parts) < 2) {
                $errors[] = "line {$n}: invalid format";
                continue;
            }

            $host = $parts[0];
            $port = (int) $parts[1];
            if ($port < 1 || $port > 65535) {
                $errors[] = "line {$n}: bad port";
                continue;
            }
            $username = $parts[2] ?? null;
            $password = $parts[3] ?? null;
            $country = isset($parts[4]) && strlen($parts[4]) === 2
                ? strtoupper($parts[4])
                : $defaultCountry;

            $existing = $this->proxies->findByHostPortProtocol($host, $port, $protocol);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            try {
                $this->proxies->create([
                    'host' => $host,
                    'port' => $port,
                    'protocol' => $protocol,
                    'username' => $username,
                    'password_enc' => $password !== null ? $crypto->encrypt($password) : null,
                    'country' => $country,
                    'health' => 'unknown',
                    'is_active' => 1,
                    'meta' => ['imported' => true],
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "line {$n}: " . $e->getMessage();
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $proxy
     * @return array{assignment_id: int, proxy_id: int, account_id: int, sticky: bool, proxy: array<string, mixed>}
     */
    private function bindInTransaction(int $accountId, int $proxyId, array $proxy): array
    {
        $this->db->beginTransaction();
        try {
            $result = $this->bindAccountToProxy($accountId, $proxyId, $proxy);
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $proxy
     * @return array{assignment_id: int, proxy_id: int, account_id: int, sticky: bool, proxy: array<string, mixed>}
     */
    private function bindAccountToProxy(int $accountId, int $proxyId, array $proxy): array
    {
        // Enforce single current assignment per account (release + insert)
        $assignmentId = $this->assignments->assign($accountId, $proxyId);
        $this->assertSingleProxy($accountId);

        // Guard: proxy must not be current for two accounts
        $others = $this->db->fetchAll(
            <<<'SQL'
                SELECT `id`, `account_id` FROM `proxy_assignments`
                WHERE `proxy_id` = :proxy_id AND `is_current` = 1 AND `account_id` <> :account_id
                SQL,
            [':proxy_id' => $proxyId, ':account_id' => $accountId]
        );
        if ($others !== []) {
            throw new RuntimeException("Proxy #{$proxyId} already assigned to another account");
        }

        $this->accounts->update($accountId, ['proxy_id' => $proxyId]);

        if ($this->fingerprints !== null) {
            $mapped = $this->geoMapper->forAccount($proxy);
            $existing = $this->fingerprints->findByAccountId($accountId);
            $fpData = [
                'timezone' => $mapped['timezone'],
                'locale' => $mapped['locale'],
                'extra' => $mapped['extra'],
            ];
            if ($existing === null) {
                $fpData['account_id'] = $accountId;
                $fpData['user_agent'] = 'Mozilla/5.0';
                $this->fingerprints->create($fpData);
            } else {
                $this->fingerprints->update((int) $existing['id'], $fpData);
            }
        }

        $fresh = $this->proxies->findById($proxyId) ?? $proxy;

        return [
            'assignment_id' => $assignmentId,
            'proxy_id' => $proxyId,
            'account_id' => $accountId,
            'sticky' => false,
            'proxy' => $fresh,
        ];
    }
}
