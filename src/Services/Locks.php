<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Support\DistributedLock;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;

/**
 * Atomic Redis locks (SET NX + TTL) for accounts/proxies + domain rate-limit.
 *
 * Keys (CONTRACT §4):
 *   farm:lock:acc:{id}
 *   farm:lock:pxy:{id}
 *   farm:rl:{key}
 */
final class Locks
{
    public const DEFAULT_ACCOUNT_TTL = 600;
    public const DEFAULT_PROXY_TTL = 120;
    public const DEFAULT_DOMAIN_WINDOW = 60;

    public function __construct(
        private readonly RedisClient|MemoryRedis $redis,
        private readonly DistributedLock $locks,
        private readonly int $accountTtl = self::DEFAULT_ACCOUNT_TTL,
        private readonly int $proxyTtl = self::DEFAULT_PROXY_TTL,
    ) {
    }

    public function accountKey(int $accountId): string
    {
        return $this->locks->accountKey($accountId);
    }

    public function proxyKey(int $proxyId): string
    {
        return $this->locks->proxyKey($proxyId);
    }

    public function rateLimitKey(string $key): string
    {
        return 'farm:rl:' . $key;
    }

    /**
     * Try SET NX EX for account. Returns token or null if busy.
     */
    public function tryAccount(int $accountId, ?int $ttlSeconds = null): ?string
    {
        return $this->locks->tryAcquire(
            $this->accountKey($accountId),
            $ttlSeconds ?? $this->accountTtl
        );
    }

    public function acquireAccount(int $accountId, ?int $ttlSeconds = null, int $waitMs = 0): string
    {
        if ($waitMs <= 0) {
            $token = $this->tryAccount($accountId, $ttlSeconds);
            if ($token === null) {
                throw new \RuntimeException("Account #{$accountId} is locked");
            }

            return $token;
        }

        return $this->locks->acquire(
            $this->accountKey($accountId),
            $ttlSeconds ?? $this->accountTtl,
            $waitMs
        );
    }

    public function releaseAccount(int $accountId, string $token): void
    {
        $this->locks->release($this->accountKey($accountId), $token);
    }

    public function isAccountLocked(int $accountId): bool
    {
        return $this->redis->exists($this->accountKey($accountId));
    }

    public function accountLockTtl(int $accountId): int
    {
        return $this->redis->ttl($this->accountKey($accountId));
    }

    /**
     * Force-delete account lock (GC / crash recovery). TTL also auto-expires.
     */
    public function forceUnlockAccount(int $accountId): bool
    {
        return $this->redis->del($this->accountKey($accountId)) > 0;
    }

    public function tryProxy(int $proxyId, ?int $ttlSeconds = null): ?string
    {
        return $this->locks->tryAcquire(
            $this->proxyKey($proxyId),
            $ttlSeconds ?? $this->proxyTtl
        );
    }

    public function releaseProxy(int $proxyId, string $token): void
    {
        $this->locks->release($this->proxyKey($proxyId), $token);
    }

    public function isProxyLocked(int $proxyId): bool
    {
        return $this->redis->exists($this->proxyKey($proxyId));
    }

    public function forceUnlockProxy(int $proxyId): bool
    {
        return $this->redis->del($this->proxyKey($proxyId)) > 0;
    }

    /**
     * Domain rate-limit: allow up to $maxHits within $windowSec.
     * Key: farm:rl:domain:{normalized}
     */
    public function allowDomain(string $domain, int $maxHits = 30, int $windowSec = self::DEFAULT_DOMAIN_WINDOW): bool
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return true;
        }

        $key = $this->rateLimitKey('domain:' . $normalized);
        $n = $this->redis->incr($key);
        if ($n === 1) {
            $this->redis->expire($key, max(1, $windowSec));
        }

        return $n <= $maxHits;
    }

    /**
     * Generic rate-limit counter under farm:rl:{key}.
     */
    public function allow(string $key, int $maxHits, int $windowSec): bool
    {
        $full = $this->rateLimitKey($key);
        $n = $this->redis->incr($full);
        if ($n === 1) {
            $this->redis->expire($full, max(1, $windowSec));
        }

        return $n <= $maxHits;
    }

    /**
     * @return list<array{key: string, account_id: int|null, proxy_id: int|null, ttl: int}>
     */
    public function listLocks(): array
    {
        $out = [];
        foreach ($this->redis->scanAll('farm:lock:*') as $key) {
            $ttl = $this->redis->ttl($key);
            $accountId = null;
            $proxyId = null;
            if (preg_match('/^farm:lock:acc:(\d+)$/', $key, $m)) {
                $accountId = (int) $m[1];
            } elseif (preg_match('/^farm:lock:pxy:(\d+)$/', $key, $m)) {
                $proxyId = (int) $m[1];
            }
            $out[] = [
                'key' => $key,
                'account_id' => $accountId,
                'proxy_id' => $proxyId,
                'ttl' => $ttl,
            ];
        }

        return $out;
    }

    /**
     * Drop locks whose keys still exist but have no matching owner process
     * (TTL already handles expiry; this is proactive GC for orphans).
     *
     * @param list<int> $busyAccountIds accounts that still have running tasks
     * @param list<int> $busyProxyIds
     * @return array{accounts: int, proxies: int}
     */
    public function releaseStale(array $busyAccountIds = [], array $busyProxyIds = []): array
    {
        $busyAcc = array_fill_keys(array_map('intval', $busyAccountIds), true);
        $busyPxy = array_fill_keys(array_map('intval', $busyProxyIds), true);
        $acc = 0;
        $pxy = 0;

        foreach ($this->listLocks() as $row) {
            if ($row['account_id'] !== null && !isset($busyAcc[$row['account_id']])) {
                if ($this->forceUnlockAccount($row['account_id'])) {
                    $acc++;
                }
            }
            if ($row['proxy_id'] !== null && !isset($busyPxy[$row['proxy_id']])) {
                if ($this->forceUnlockProxy($row['proxy_id'])) {
                    $pxy++;
                }
            }
        }

        return ['accounts' => $acc, 'proxies' => $pxy];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0] ?? $domain;
        $domain = explode(':', $domain)[0] ?? $domain;

        return preg_replace('/[^a-z0-9.\-]/', '', $domain) ?? '';
    }
}
