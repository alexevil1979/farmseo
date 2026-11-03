<?php

declare(strict_types=1);

namespace Farm\Support;

use RuntimeException;

/**
 * Minimal Redis client over TCP (RESP). No ext-redis required.
 */
final class RedisClient
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     password?: string|null,
     *     database?: int,
     *     timeout?: float
     * } $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 6379);
        $timeout = (float) ($this->config['timeout'] ?? 2.0);

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("Redis connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int) ceil($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
        $this->socket = $socket;

        $password = $this->config['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $this->command('AUTH', $password);
        }

        $db = (int) ($this->config['database'] ?? 0);
        if ($db > 0) {
            $this->command('SELECT', (string) $db);
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param string ...$args
     */
    public function command(string $cmd, string ...$args): mixed
    {
        $this->connect();
        $parts = array_merge([$cmd], $args);
        $payload = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        $written = fwrite($this->socket, $payload);
        if ($written === false) {
            $this->close();
            throw new RuntimeException('Redis write failed');
        }

        return $this->readReply();
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds !== null) {
            $r = $this->command('SET', $key, $value, 'EX', (string) $ttlSeconds);

            return $r === 'OK';
        }
        $r = $this->command('SET', $key, $value);

        return $r === 'OK';
    }

    /**
     * SET key value NX EX ttl — returns true if lock acquired.
     */
    public function setNxEx(string $key, string $value, int $ttlSeconds): bool
    {
        $r = $this->command('SET', $key, $value, 'EX', (string) $ttlSeconds, 'NX');

        return $r === 'OK';
    }

    public function get(string $key): ?string
    {
        $r = $this->command('GET', $key);

        return $r === null ? null : (string) $r;
    }

    public function del(string $key): int
    {
        return (int) $this->command('DEL', $key);
    }

    /**
     * @param string ...$keys
     */
    public function delMany(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->command('DEL', ...$keys);
    }

    public function exists(string $key): bool
    {
        return (int) $this->command('EXISTS', $key) > 0;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (int) $this->command('EXPIRE', $key, (string) $ttlSeconds) === 1;
    }

    public function ttl(string $key): int
    {
        return (int) $this->command('TTL', $key);
    }

    public function incr(string $key): int
    {
        return (int) $this->command('INCR', $key);
    }

    public function incrBy(string $key, int $by): int
    {
        return (int) $this->command('INCRBY', $key, (string) $by);
    }

    public function decr(string $key): int
    {
        return (int) $this->command('DECR', $key);
    }

    public function lpush(string $key, string ...$values): int
    {
        if ($values === []) {
            return (int) $this->command('LLEN', $key);
        }

        return (int) $this->command('LPUSH', $key, ...$values);
    }

    public function llen(string $key): int
    {
        return (int) $this->command('LLEN', $key);
    }

    public function rpop(string $key): ?string
    {
        $r = $this->command('RPOP', $key);

        return $r === null ? null : (string) $r;
    }

    /**
     * KEYS pattern — prefer for ops/GC; avoid on huge keyspaces in hot path.
     *
     * @return list<string>
     */
    public function keys(string $pattern): array
    {
        $r = $this->command('KEYS', $pattern);
        if (!is_array($r)) {
            return [];
        }

        return array_map(static fn (mixed $k): string => (string) $k, $r);
    }

    /**
     * SCAN cursor iteration (safer than KEYS on large DBs).
     *
     * @return list<string>
     */
    public function scanAll(string $pattern, int $count = 100): array
    {
        $out = [];
        $cursor = '0';
        do {
            $reply = $this->command('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', (string) $count);
            if (!is_array($reply) || count($reply) < 2) {
                break;
            }
            $cursor = (string) $reply[0];
            $batch = $reply[1] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $k) {
                    $out[] = (string) $k;
                }
            }
        } while ($cursor !== '0');

        return $out;
    }

    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        $numKeys = count($keys);
        $parts = array_merge([(string) $numKeys], $keys, $args);

        return $this->command('EVAL', $script, ...$parts);
    }

    private function readReply(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $this->close();
            throw new RuntimeException('Redis read failed');
        }

        $type = $line[0];
        $payload = substr($line, 1, -2); // strip \r\n

        return match ($type) {
            '+' => $payload,
            '-' => throw new RuntimeException('Redis error: ' . $payload),
            ':' => (int) $payload,
            '$' => $this->readBulk((int) $payload),
            '*' => $this->readArray((int) $payload),
            default => throw new RuntimeException('Unknown Redis reply type: ' . $type),
        };
    }

    private function readBulk(int $len): ?string
    {
        if ($len < 0) {
            return null;
        }
        $data = '';
        $remaining = $len + 2; // + \r\n
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw new RuntimeException('Redis bulk read failed');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return substr($data, 0, $len);
    }

    /** @return list<mixed> */
    private function readArray(int $count): array
    {
        if ($count < 0) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->readReply();
        }

        return $out;
    }
}
