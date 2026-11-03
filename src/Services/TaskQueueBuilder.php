<?php

declare(strict_types=1);

namespace Farm\Services;

use DateInterval;
use DateTimeImmutable;
use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Repositories\TaskRepository;
use Farm\Support\Crypto;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;
use Throwable;

/**
 * Builds Redis farm:q:tasks messages and is the only LPUSH admission path.
 *
 * Every enqueue goes through Admission: allow → claim + LPUSH;
 * defer → status=pending, defer_reason, scheduled_at += backoff (task kept).
 */
final class TaskQueueBuilder
{
    public const QUEUE_KEY = 'farm:q:tasks';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly ProxyRepository $proxies,
        private readonly AccountFingerprintRepository $fingerprints,
        private readonly Crypto $crypto,
        private readonly FingerprintGenerator $fpGenerator = new FingerprintGenerator(),
        private readonly ?Admission $admission = null,
        private readonly ?TaskRepository $tasks = null,
        private readonly RedisClient|MemoryRedis|null $redis = null,
    ) {
    }

    /**
     * Admit + LPUSH. Sole path that may push to farm:q:tasks.
     *
     * @param array<string, mixed> $task DB task row
     * @return array{
     *     decision: 'allow'|'defer',
     *     status: string,
     *     defer_reason: string|null,
     *     scheduled_at: string|null,
     *     task_id: int
     * }
     */
    public function enqueue(array $task, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0 || $this->admission === null || $this->tasks === null || $this->redis === null) {
            // Miswired: never LPUSH without Admission.
            return $this->deferLocal($task, Admission::REASON_CHROME_CAP, $now);
        }

        $accountId = $this->resolveAccountId($task);
        $proxyId = $this->resolveProxyId($accountId, $task);

        $decision = $this->admission->canAdmit(
            $accountId > 0 ? $accountId : null,
            $proxyId > 0 ? $proxyId : null,
            1
        );

        if ($decision['decision'] !== Admission::ALLOW) {
            return $this->applyDefer($task, (string) ($decision['reason'] ?? Admission::REASON_CHROME_CAP), $now);
        }

        if (!$this->tasks->claimForQueue($taskId)) {
            // Already claimed / not pending — treat as deferred without losing row.
            $fresh = $this->tasks->findById($taskId);

            return [
                'decision' => Admission::DEFER,
                'status' => (string) ($fresh['status'] ?? 'pending'),
                'defer_reason' => Admission::REASON_BATCH,
                'scheduled_at' => isset($fresh['scheduled_at']) ? (string) $fresh['scheduled_at'] : null,
                'task_id' => $taskId,
            ];
        }

        $fresh = $this->tasks->findById($taskId);
        if ($fresh === null) {
            return [
                'decision' => Admission::DEFER,
                'status' => 'pending',
                'defer_reason' => Admission::REASON_CHROME_CAP,
                'scheduled_at' => null,
                'task_id' => $taskId,
            ];
        }

        try {
            $message = $this->buildMessage($fresh);
            $this->redis->lpush(
                self::QUEUE_KEY,
                json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $this->admission->clearDeferReason($taskId);

            return [
                'decision' => Admission::ALLOW,
                'status' => 'queued',
                'defer_reason' => null,
                'scheduled_at' => isset($fresh['scheduled_at']) ? (string) $fresh['scheduled_at'] : null,
                'task_id' => $taskId,
            ];
        } catch (Throwable) {
            return $this->applyDefer($fresh, Admission::REASON_CHROME_CAP, $now);
        }
    }

    /**
     * Mark task deferred without LPUSH (batch overflow / missing wiring).
     *
     * @param array<string, mixed> $task
     * @return array{
     *     decision: 'defer',
     *     status: string,
     *     defer_reason: string,
     *     scheduled_at: string|null,
     *     task_id: int
     * }
     */
    public function defer(array $task, string $reason = Admission::REASON_BATCH, ?DateTimeImmutable $now = null): array
    {
        return $this->applyDefer($task, $reason, $now ?? new DateTimeImmutable('now'));
    }

    /**
     * @param array<string, mixed> $task DB task row
     * @return array<string, mixed>
     */
    public function buildMessage(array $task): array
    {
        $payload = $this->decodeJson($task['payload'] ?? null) ?? [];
        $accountId = isset($task['account_id'])
            ? (int) $task['account_id']
            : (int) ($payload['account_id'] ?? 0);

        $message = [
            'task_id' => (int) $task['id'],
            'priority' => (int) ($task['priority'] ?? 0),
            'timeout' => (int) ($task['timeout'] ?? 300),
            'attempts' => (int) ($task['attempts'] ?? 0),
            'max_attempts' => (int) ($task['max_attempts'] ?? 3),
            'account_id' => $accountId > 0 ? $accountId : null,
            'scenario_id' => isset($task['scenario_id']) ? (int) $task['scenario_id'] : null,
            'type' => (string) ($task['type'] ?? ($payload['type'] ?? 'warmup')),
            'payload' => $payload,
        ];

        if ($accountId > 0) {
            $account = $this->accounts->findById($accountId);
            $nodeId = $account !== null && isset($account['assigned_node_id'])
                ? (int) $account['assigned_node_id']
                : 0;
            if ($nodeId > 0) {
                $message['node_id'] = $nodeId;
            }

            $proxy = $this->resolveProxy($accountId, $payload);
            if ($proxy !== null) {
                $message['proxy'] = $proxy;
                $message['proxy_id'] = $proxy['id'] ?? null;
            }
            $fp = $this->resolveFingerprint($accountId, $payload);
            if ($fp !== null) {
                $message['fingerprint'] = $fp;
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{
     *     decision: 'defer',
     *     status: string,
     *     defer_reason: string,
     *     scheduled_at: string|null,
     *     task_id: int
     * }
     */
    private function applyDefer(array $task, string $reason, DateTimeImmutable $now): array
    {
        $taskId = (int) ($task['id'] ?? 0);
        $backoff = $this->admission?->backoffSeconds($reason) ?? Admission::DEFAULT_BACKOFF_SEC;

        $base = $now;
        if (!empty($task['scheduled_at']) && is_string($task['scheduled_at'])) {
            try {
                $existing = new DateTimeImmutable($task['scheduled_at']);
                if ($existing > $base) {
                    $base = $existing;
                }
            } catch (Throwable) {
            }
        }
        $scheduledAt = $base->add(new DateInterval('PT' . max(1, $backoff) . 'S'))->format('Y-m-d H:i:s.v');

        if ($this->tasks !== null && $taskId > 0) {
            $this->tasks->releaseToPending($taskId, [
                'scheduled_at' => $scheduledAt,
            ]);
            // Keep defer_reason out of payload/schema: Redis side-channel.
            $this->admission?->rememberDeferReason($taskId, $reason);
        }

        return [
            'decision' => Admission::DEFER,
            'status' => 'pending',
            'defer_reason' => $reason,
            'scheduled_at' => $scheduledAt,
            'task_id' => $taskId,
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @return array{
     *     decision: 'defer',
     *     status: string,
     *     defer_reason: string,
     *     scheduled_at: string|null,
     *     task_id: int
     * }
     */
    private function deferLocal(array $task, string $reason, DateTimeImmutable $now): array
    {
        return $this->applyDefer($task, $reason, $now);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function resolveAccountId(array $task): int
    {
        if (isset($task['account_id'])) {
            return (int) $task['account_id'];
        }
        $payload = $this->decodeJson($task['payload'] ?? null) ?? [];

        return (int) ($payload['account_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function resolveProxyId(int $accountId, array $task): int
    {
        $payload = $this->decodeJson($task['payload'] ?? null) ?? [];
        if (isset($payload['proxy']) && is_array($payload['proxy']) && isset($payload['proxy']['id'])) {
            return (int) $payload['proxy']['id'];
        }
        if ($accountId <= 0) {
            return 0;
        }
        $account = $this->accounts->findById($accountId);

        return $account !== null && isset($account['proxy_id']) ? (int) $account['proxy_id'] : 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveProxy(int $accountId, array $payload): ?array
    {
        if (isset($payload['proxy']) && is_array($payload['proxy']) && !empty($payload['proxy']['host'])) {
            return $this->normalizeProxy($payload['proxy']);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return null;
        }
        $proxyId = isset($account['proxy_id']) ? (int) $account['proxy_id'] : 0;
        if ($proxyId <= 0) {
            return null;
        }
        $row = $this->proxies->findById($proxyId);
        if ($row === null) {
            return null;
        }

        return $this->normalizeProxy($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeProxy(array $row): array
    {
        $password = null;
        if (isset($row['password']) && is_string($row['password']) && $row['password'] !== '') {
            $password = $row['password'];
        } elseif (!empty($row['password_enc'])) {
            try {
                $password = $this->crypto->decryptOrPlain((string) $row['password_enc']);
            } catch (Throwable) {
                $password = null;
            }
        }

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'host' => (string) ($row['host'] ?? ''),
            'port' => (int) ($row['port'] ?? 0),
            'protocol' => (string) ($row['protocol'] ?? 'http'),
            'username' => $row['username'] ?? $row['user'] ?? null,
            'password' => $password,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveFingerprint(int $accountId, array $payload): ?array
    {
        if (isset($payload['fingerprint']) && is_array($payload['fingerprint'])) {
            return $payload['fingerprint'];
        }

        $row = $this->fingerprints->findByAccountId($accountId);
        if ($row === null) {
            return null;
        }

        $fp = $this->fpGenerator->fromRow($row);
        if ($fp !== null) {
            return $fp;
        }

        return [
            'ua' => $row['user_agent'] ?? null,
            'platform' => $row['platform'] ?? null,
            'screen' => [
                'width' => $row['viewport_w'] ?? null,
                'height' => $row['viewport_h'] ?? null,
            ],
            'timezone' => $row['timezone'] ?? null,
            'lang' => $row['locale'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
