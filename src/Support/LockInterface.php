<?php

declare(strict_types=1);

namespace Farm\Support;

interface LockInterface
{
    public function proxyKey(int $proxyId): string;

    public function accountKey(int $accountId): string;

    /** @return string token */
    public function acquire(string $key, ?int $ttlSeconds = null, int $waitMs = 3000, int $retryMs = 50): string;

    public function tryAcquire(string $key, ?int $ttlSeconds = null): ?string;

    public function release(string $key, string $token): void;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $key, callable $fn, ?int $ttlSeconds = null): mixed;
}
