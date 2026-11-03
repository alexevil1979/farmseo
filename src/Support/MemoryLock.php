<?php

declare(strict_types=1);

namespace Farm\Support;

use RuntimeException;

/**
 * Process-local lock for tests / import without Redis.
 */
final class MemoryLock implements LockInterface
{
    /** @var array<string, array{token: string, expires: float}> */
    private static array $locks = [];

    public function proxyKey(int $proxyId): string
    {
        return 'farm:lock:pxy:' . $proxyId;
    }

    public function accountKey(int $accountId): string
    {
        return 'farm:lock:acc:' . $accountId;
    }

    public function acquire(string $key, ?int $ttlSeconds = null, int $waitMs = 3000, int $retryMs = 50): string
    {
        $ttl = $ttlSeconds ?? 30;
        $deadline = microtime(true) + ($waitMs / 1000);
        do {
            $token = $this->tryAcquire($key, $ttl);
            if ($token !== null) {
                return $token;
            }
            usleep($retryMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Failed to acquire lock: {$key}");
    }

    public function tryAcquire(string $key, ?int $ttlSeconds = null): ?string
    {
        $this->purge($key);
        if (isset(self::$locks[$key])) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        self::$locks[$key] = [
            'token' => $token,
            'expires' => microtime(true) + ($ttlSeconds ?? 30),
        ];

        return $token;
    }

    public function release(string $key, string $token): void
    {
        if (isset(self::$locks[$key]) && self::$locks[$key]['token'] === $token) {
            unset(self::$locks[$key]);
        }
    }

    public function withLock(string $key, callable $fn, ?int $ttlSeconds = null): mixed
    {
        $token = $this->acquire($key, $ttlSeconds);
        try {
            return $fn();
        } finally {
            $this->release($key, $token);
        }
    }

    public static function reset(): void
    {
        self::$locks = [];
    }

    private function purge(string $key): void
    {
        if (isset(self::$locks[$key]) && self::$locks[$key]['expires'] < microtime(true)) {
            unset(self::$locks[$key]);
        }
    }
}
