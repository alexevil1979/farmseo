<?php

declare(strict_types=1);

namespace Farm\Middleware;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Support\RedisClient;
use Throwable;

/**
 * Sliding fixed-window rate limit via Redis key farm:rl:{key}.
 */
final class RateLimit
{
    public function __construct(
        private readonly ?RedisClient $redis,
        private readonly int $maxRequests = 120,
        private readonly int $windowSeconds = 60,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $tokenId = $request->getAttribute('token_id');
        $user = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        if ($tokenId !== null) {
            $bucket = 'tok:' . (int) $tokenId;
        } elseif ($userId > 0) {
            $bucket = 'uid:' . $userId;
        } else {
            $bucket = 'ip:' . $request->ip();
        }

        $key = 'farm:rl:' . $bucket;
        $count = $this->hit($key);
        if ($count > $this->maxRequests) {
            throw new HttpException(
                'rate_limited',
                'Too many requests',
                429,
                ['retry_after' => $this->windowSeconds]
            );
        }

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxRequests - $count));
    }

    private function hit(string $key): int
    {
        if ($this->redis === null) {
            return $this->memoryHit($key);
        }

        try {
            $count = $this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, $this->windowSeconds);
            }

            return $count;
        } catch (Throwable) {
            return $this->memoryHit($key);
        }
    }

    /** @var array<string, array{count: int, reset: int}> */
    private static array $memory = [];

    private function memoryHit(string $key): int
    {
        $now = time();
        $slot = self::$memory[$key] ?? null;
        if ($slot === null || $slot['reset'] <= $now) {
            self::$memory[$key] = ['count' => 1, 'reset' => $now + $this->windowSeconds];

            return 1;
        }
        self::$memory[$key]['count']++;

        return self::$memory[$key]['count'];
    }
}
