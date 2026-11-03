<?php

declare(strict_types=1);

namespace Farm\Support;

use RuntimeException;

/**
 * Redis distributed lock. Proxy locks: farm:lock:pxy:{id}
 */
final class DistributedLock implements LockInterface
{
    private const RELEASE_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
  return redis.call("del", KEYS[1])
end
return 0
LUA;

    public function __construct(
        private readonly RedisClient|MemoryRedis $redis,
        private readonly int $defaultTtlSeconds = 30,
    ) {
    }

    public function proxyKey(int $proxyId): string
    {
        return 'farm:lock:pxy:' . $proxyId;
    }

    public function accountKey(int $accountId): string
    {
        return 'farm:lock:acc:' . $accountId;
    }

    /**
     * @return string token to pass to release()
     */
    public function acquire(string $key, ?int $ttlSeconds = null, int $waitMs = 3000, int $retryMs = 50): string
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + ($waitMs / 1000);

        do {
            if ($this->redis->setNxEx($key, $token, $ttl)) {
                return $token;
            }
            usleep($retryMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Failed to acquire lock: {$key}");
    }

    public function tryAcquire(string $key, ?int $ttlSeconds = null): ?string
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        $token = bin2hex(random_bytes(16));
        if ($this->redis->setNxEx($key, $token, $ttl)) {
            return $token;
        }

        return null;
    }

    public function release(string $key, string $token): void
    {
        if ($this->redis instanceof MemoryRedis) {
            if ($this->redis->get($key) === $token) {
                $this->redis->del($key);
            }

            return;
        }
        $this->redis->eval(self::RELEASE_SCRIPT, [$key], [$token]);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $key, callable $fn, ?int $ttlSeconds = null): mixed
    {
        $token = $this->acquire($key, $ttlSeconds);
        try {
            return $fn();
        } finally {
            $this->release($key, $token);
        }
    }
}
