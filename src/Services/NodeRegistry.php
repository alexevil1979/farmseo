<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\AlertRepository;
use Farm\Repositories\NodeRepository;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;
use RuntimeException;
use Throwable;

/**
 * Multi-node registry: sticky account → node, least-loaded assignment, HB aliveness.
 *
 * Redis: SETEX farm:node:{id}:hb 15 (worker heartbeat.js).
 * Profile remigration on dead HB: ProfileMigrationJob (14b) — not silent reassign here.
 */
final class NodeRegistry
{
    public const HB_TTL = 15;

    public const HB_KEY = 'farm:node:%d:hb';

    public const ALERT_SOURCE = 'node_registry';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly AccountRepository $accounts,
        private readonly RedisClient|MemoryRedis|null $redis = null,
        private readonly ?AlertRepository $alerts = null,
        private readonly ?string $fallbackWorkerUrl = null,
    ) {
    }

    public static function hbKey(int $nodeId): string
    {
        return sprintf(self::HB_KEY, $nodeId);
    }

    /**
     * Assign least-loaded alive node on first launch; sticky thereafter.
     *
     * @return array<string, mixed> nodes row
     */
    public function assignAccount(int $accountId): array
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found');
        }

        $existing = isset($account['assigned_node_id']) ? (int) $account['assigned_node_id'] : 0;
        if ($existing > 0) {
            $node = $this->nodes->findById($existing);
            if ($node === null) {
                throw new RuntimeException("Assigned node {$existing} missing for account {$accountId}");
            }

            return $node;
        }

        $registered = $this->nodes->getAll([], limit: 1);
        if ($registered === []) {
            // Single-node legacy: empty registry — caller may use WORKER_URL.
            throw new RuntimeException('No nodes registered');
        }

        $picked = $this->pickLeastLoaded();
        if ($picked === null) {
            throw new RuntimeException('No alive nodes with free capacity');
        }

        $this->accounts->update($accountId, ['assigned_node_id' => (int) $picked['id']]);

        return $picked;
    }

    /**
     * Resolve HTTP base URL for worker internal API (session/screenshot).
     */
    public function resolveAddress(int $accountId, bool $assignIfMissing = true): string
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found');
        }

        $nodeId = isset($account['assigned_node_id']) ? (int) $account['assigned_node_id'] : 0;
        if ($nodeId <= 0) {
            if (!$assignIfMissing) {
                if ($this->fallbackWorkerUrl !== null && $this->fallbackWorkerUrl !== '') {
                    return rtrim($this->fallbackWorkerUrl, '/');
                }
                throw new RuntimeException('Account has no assigned node');
            }
            try {
                $node = $this->assignAccount($accountId);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'No nodes registered'
                    && $this->fallbackWorkerUrl !== null
                    && $this->fallbackWorkerUrl !== ''
                ) {
                    return rtrim($this->fallbackWorkerUrl, '/');
                }
                throw $e;
            }
            $nodeId = (int) $node['id'];
        } else {
            $node = $this->nodes->findById($nodeId);
            if ($node === null) {
                throw new RuntimeException("Assigned node {$nodeId} missing");
            }
        }

        if (!$this->isAlive($nodeId)) {
            $this->markAccountNodeDead($accountId, $nodeId);

            throw new RuntimeException("Assigned node {$nodeId} heartbeat expired");
        }

        $this->touchDbHeartbeat($nodeId);

        return rtrim((string) ($node['address'] ?? ''), '/');
    }

    public function isAlive(int $nodeId): bool
    {
        if ($this->redis === null) {
            $node = $this->nodes->findById($nodeId);
            if ($node === null) {
                return false;
            }
            if (($node['status'] ?? '') === 'offline') {
                return false;
            }
            $hb = $node['last_hb'] ?? null;
            if ($hb === null || $hb === '') {
                return false;
            }
            $ts = strtotime((string) $hb);

            return $ts !== false && (time() - $ts) <= self::HB_TTL;
        }

        try {
            $raw = $this->redis->get(self::hbKey($nodeId));

            return $raw !== null && $raw !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{capacity: int, load: int, address: ?string, worker_id: ?string}|null
     */
    public function readHeartbeat(int $nodeId): ?array
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            $raw = $this->redis->get(self::hbKey($nodeId));
            if ($raw === null || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['capacity' => 0, 'load' => 0, 'address' => null, 'worker_id' => null];
            }

            return [
                'capacity' => (int) ($decoded['capacity'] ?? 0),
                'load' => (int) ($decoded['load'] ?? 0),
                'address' => isset($decoded['address']) ? (string) $decoded['address'] : null,
                'worker_id' => isset($decoded['worker_id']) ? (string) $decoded['worker_id'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Least loaded alive node with load < capacity. Does not reassign pinned accounts.
     *
     * @return array<string, mixed>|null
     */
    public function pickLeastLoaded(): ?array
    {
        $best = null;
        $bestScore = null;

        foreach ($this->nodes->findSchedulable() as $node) {
            $id = (int) $node['id'];
            if (!$this->isAlive($id)) {
                continue;
            }
            $this->touchDbHeartbeat($id);

            $hb = $this->readHeartbeat($id);
            $capacity = (int) ($node['capacity'] ?? 0);
            if ($hb !== null && ($hb['capacity'] ?? 0) > 0) {
                $capacity = (int) $hb['capacity'];
            }
            if ($capacity <= 0) {
                continue;
            }
            $load = (int) ($hb['load'] ?? 0);
            if ($load >= $capacity) {
                continue;
            }
            $score = $load / $capacity;
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }

        return $best;
    }

    /**
     * Dead assigned node: account → error, node → offline, alert. No remigration.
     */
    public function markAccountNodeDead(int $accountId, int $nodeId): void
    {
        $this->accounts->updateStatus($accountId, 'error');
        try {
            $this->nodes->markOffline($nodeId);
        } catch (Throwable) {
        }

        if ($this->alerts === null) {
            return;
        }

        $node = $this->nodes->findById($nodeId);
        $address = $node['address'] ?? '';
        try {
            $this->alerts->create([
                'severity' => 'critical',
                'source' => self::ALERT_SOURCE,
                'message' => "Node heartbeat lost: {$nodeId}" . ($address !== '' ? " ({$address})" : ''),
                'entity_type' => 'node',
                'entity_id' => $nodeId,
                'is_acked' => 0,
                'meta' => [
                    'rule' => 'node_down',
                    'node_id' => $nodeId,
                    'account_id' => $accountId,
                    'address' => $address,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Test helper: publish node HB into Redis (same shape as worker heartbeat.js).
     *
     * @param array{capacity?: int, load?: int, address?: string, worker_id?: string} $payload
     */
    public function publishHeartbeat(int $nodeId, array $payload = [], int $ttl = self::HB_TTL): void
    {
        if ($this->redis === null) {
            $this->nodes->updateHeartbeat($nodeId);

            return;
        }
        $body = json_encode([
            'ts' => (int) (microtime(true) * 1000),
            'capacity' => (int) ($payload['capacity'] ?? 40),
            'load' => (int) ($payload['load'] ?? 0),
            'address' => $payload['address'] ?? null,
            'worker_id' => $payload['worker_id'] ?? null,
        ], JSON_THROW_ON_ERROR);
        $this->redis->set(self::hbKey($nodeId), $body, $ttl);
        $this->touchDbHeartbeat($nodeId);
    }

    private function touchDbHeartbeat(int $nodeId): void
    {
        try {
            $this->nodes->updateHeartbeat($nodeId);
        } catch (Throwable) {
        }
    }
}
