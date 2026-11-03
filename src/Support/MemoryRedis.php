<?php

declare(strict_types=1);

namespace Farm\Support;

/**
 * In-process Redis stand-in for tests / offline admission paths.
 * Implements the subset used by Admission, TaskQueueBuilder, Scheduler, Locks.
 */
final class MemoryRedis
{
    /** @var array<string, string> */
    private array $kv = [];

    /** @var array<string, int> */
    private array $ttlAt = [];

    /** @var array<string, list<string>> */
    private array $lists = [];

    public function connect(): void
    {
    }

    public function close(): void
    {
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        $this->expireDue();
        $this->kv[$key] = $value;
        if ($ttlSeconds !== null) {
            $this->ttlAt[$key] = time() + max(1, $ttlSeconds);
        } else {
            unset($this->ttlAt[$key]);
        }

        return true;
    }

    public function setNxEx(string $key, string $value, int $ttlSeconds): bool
    {
        $this->expireDue();
        if (isset($this->kv[$key])) {
            return false;
        }
        $this->kv[$key] = $value;
        $this->ttlAt[$key] = time() + max(1, $ttlSeconds);

        return true;
    }

    public function get(string $key): ?string
    {
        $this->expireDue();

        return $this->kv[$key] ?? null;
    }

    public function del(string $key): int
    {
        $n = 0;
        if (isset($this->kv[$key])) {
            unset($this->kv[$key], $this->ttlAt[$key]);
            $n++;
        }
        if (isset($this->lists[$key])) {
            unset($this->lists[$key]);
            $n = max(1, $n);
        }

        return $n;
    }

    public function delMany(string ...$keys): int
    {
        $n = 0;
        foreach ($keys as $key) {
            $n += $this->del($key);
        }

        return $n;
    }

    public function exists(string $key): bool
    {
        $this->expireDue();

        return isset($this->kv[$key]) || isset($this->lists[$key]);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        $this->expireDue();
        if (!isset($this->kv[$key]) && !isset($this->lists[$key])) {
            return false;
        }
        $this->ttlAt[$key] = time() + max(1, $ttlSeconds);

        return true;
    }

    public function ttl(string $key): int
    {
        $this->expireDue();
        if (!isset($this->kv[$key]) && !isset($this->lists[$key])) {
            return -2;
        }
        if (!isset($this->ttlAt[$key])) {
            return -1;
        }

        return max(0, $this->ttlAt[$key] - time());
    }

    public function incr(string $key): int
    {
        $this->expireDue();
        $n = (int) ($this->kv[$key] ?? 0) + 1;
        $this->kv[$key] = (string) $n;

        return $n;
    }

    public function incrBy(string $key, int $by): int
    {
        $this->expireDue();
        $n = (int) ($this->kv[$key] ?? 0) + $by;
        $this->kv[$key] = (string) $n;

        return $n;
    }

    public function decr(string $key): int
    {
        return $this->incrBy($key, -1);
    }

    public function lpush(string $key, string ...$values): int
    {
        $this->expireDue();
        if (!isset($this->lists[$key])) {
            $this->lists[$key] = [];
        }
        foreach ($values as $v) {
            array_unshift($this->lists[$key], $v);
        }

        return count($this->lists[$key]);
    }

    public function llen(string $key): int
    {
        $this->expireDue();

        return isset($this->lists[$key]) ? count($this->lists[$key]) : 0;
    }

    public function rpop(string $key): ?string
    {
        $this->expireDue();
        if (!isset($this->lists[$key]) || $this->lists[$key] === []) {
            return null;
        }

        return array_pop($this->lists[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(string $pattern): array
    {
        $this->expireDue();
        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $out = [];
        foreach (array_keys($this->kv) as $k) {
            if (preg_match($regex, $k)) {
                $out[] = $k;
            }
        }
        foreach (array_keys($this->lists) as $k) {
            if (preg_match($regex, $k) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * @return \Generator<int, string>
     */
    public function scanAll(string $pattern): \Generator
    {
        foreach ($this->keys($pattern) as $k) {
            yield $k;
        }
    }

    private function expireDue(): void
    {
        $now = time();
        foreach ($this->ttlAt as $key => $at) {
            if ($at <= $now) {
                unset($this->kv[$key], $this->lists[$key], $this->ttlAt[$key]);
            }
        }
    }
}
