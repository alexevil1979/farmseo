<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\AlertRepository;
use Farm\Repositories\NodeRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\Governor;
use Farm\Services\NodeRegistry;
use Farm\Services\ServiceFactory;
use Farm\Services\SessionRouter;
use Farm\Services\TaskQueueBuilder;
use Farm\Support\MemoryRedis;
use Farm\Tests\TestCase;
use RuntimeException;

final class NodeRegistryTest extends TestCase
{
    private function factory(): ServiceFactory
    {
        $factory = new ServiceFactory($this->db, [
            'internal_token' => 'test-internal',
            'app_key' => 'test-key',
            'root' => dirname(__DIR__, 2),
            'worker_url' => 'http://127.0.0.1:3000',
            'redis_memory' => true,
            'governor' => [
                'max_chrome' => 40,
                'enqueue_batch' => 50,
                'account_limit' => 1,
                'proxy_limit' => 1,
                'admit_backoff_sec' => 5,
                'cpu_threshold' => 80.0,
                'ram_threshold' => 90.0,
            ],
        ]);
        $factory->useMemoryRedis(new MemoryRedis());
        $factory->governor()->setLoadOverride(5.0, 5.0);
        $factory->redis()->set(Governor::CFG_MAX_CHROME, '40');

        return $factory;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedTwoNodes(NodeRepository $nodes, MemoryRedis $redis): array
    {
        $a = $nodes->create([
            'address' => 'http://10.0.0.1:3000',
            'capacity' => 10,
            'status' => 'online',
        ]);
        $b = $nodes->create([
            'address' => 'http://10.0.0.2:3000',
            'capacity' => 10,
            'status' => 'online',
        ]);
        $registry = new NodeRegistry($nodes, new AccountRepository($this->db), $redis);
        $registry->publishHeartbeat($a, ['capacity' => 10, 'load' => 8, 'address' => 'http://10.0.0.1:3000']);
        $registry->publishHeartbeat($b, ['capacity' => 10, 'load' => 1, 'address' => 'http://10.0.0.2:3000']);

        return [$a, $b];
    }

    public function testAccountPinnedToLeastLoadedNodeOnFirstAssign(): void
    {
        $factory = $this->factory();
        $redis = $factory->redis();
        self::assertInstanceOf(MemoryRedis::class, $redis);
        $nodes = $factory->nodeRepository();
        [$nodeA, $nodeB] = $this->seedTwoNodes($nodes, $redis);

        $accounts = $factory->accountRepository();
        $accId = $accounts->create([
            'login' => 'sticky@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
        ]);

        $registry = $factory->nodeRegistry();
        $picked = $registry->assignAccount($accId);
        self::assertSame($nodeB, (int) $picked['id']);

        $row = $accounts->findById($accId);
        self::assertSame($nodeB, (int) $row['assigned_node_id']);

        // Second call is sticky — does not move to another node.
        $again = $registry->assignAccount($accId);
        self::assertSame($nodeB, (int) $again['id']);

        $router = $factory->sessionRouter();
        self::assertSame('http://10.0.0.2:3000', $router->resolveWorkerUrl($accId));
        self::assertNotSame($nodeA, (int) $accounts->findById($accId)['assigned_node_id']);
    }

    public function testDeadNodeDoesNotRerouteWithoutMigration(): void
    {
        $factory = $this->factory();
        $redis = $factory->redis();
        self::assertInstanceOf(MemoryRedis::class, $redis);
        $nodes = $factory->nodeRepository();
        [$nodeA, $nodeB] = $this->seedTwoNodes($nodes, $redis);

        $accounts = $factory->accountRepository();
        $accId = $accounts->create([
            'login' => 'deadnode@test',
            'password_enc' => 'x',
            'status' => 'active',
            'trust_score' => '1.00',
            'assigned_node_id' => $nodeA,
        ]);

        // Kill node A HB; node B still alive.
        $redis->del(NodeRegistry::hbKey($nodeA));

        $router = new SessionRouter($factory->nodeRegistry(), 'http://127.0.0.1:9999');
        try {
            $router->resolveWorkerUrl($accId);
            self::fail('Expected RuntimeException for dead assigned node');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('heartbeat expired', $e->getMessage());
        }

        $row = $accounts->findById($accId);
        self::assertSame('error', $row['status']);
        // Still pinned to dead node — no silent remigration to node B.
        self::assertSame($nodeA, (int) $row['assigned_node_id']);
        self::assertSame('offline', $nodes->findById($nodeA)['status']);

        $alerts = (new AlertRepository($this->db))->findUnacked();
        self::assertNotEmpty($alerts);
        self::assertSame('node', $alerts[0]['entity_type']);
        self::assertSame($nodeA, (int) $alerts[0]['entity_id']);

        // Scheduler must not push the account onto another node's queue.
        $tasks = new TaskRepository($this->db);
        $taskId = $tasks->create([
            'type' => 'warmup',
            'priority' => 1,
            'status' => 'pending',
            'account_id' => $accId,
            'payload' => ['type' => 'warmup', 'account_id' => $accId],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 60,
            'scheduled_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.v'),
        ]);

        $stats = $factory->scheduler()->run();
        self::assertGreaterThanOrEqual(1, $stats['skipped_node']);
        self::assertSame(0, $stats['enqueued']);
        self::assertSame(0, $factory->redis()->llen(TaskQueueBuilder::QUEUE_KEY));
        self::assertSame('pending', $tasks->findById($taskId)['status']);
        self::assertSame($nodeA, (int) $accounts->findById($accId)['assigned_node_id']);
        self::assertSame($nodeB, $nodeB); // keep second node unused for this account
    }

    public function testQueueMessageCarriesAssignedNodeId(): void
    {
        $factory = $this->factory();
        $redis = $factory->redis();
        self::assertInstanceOf(MemoryRedis::class, $redis);
        $nodes = $factory->nodeRepository();
        [, $nodeB] = $this->seedTwoNodes($nodes, $redis);

        $accounts = $factory->accountRepository();
        $accId = $accounts->create([
            'login' => 'msg@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
            'assigned_node_id' => $nodeB,
        ]);

        $tasks = new TaskRepository($this->db);
        $taskId = $tasks->create([
            'type' => 'warmup',
            'priority' => 5,
            'status' => 'pending',
            'account_id' => $accId,
            'payload' => ['type' => 'warmup', 'account_id' => $accId],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 120,
        ]);
        $task = $tasks->findById($taskId);
        $msg = $factory->taskQueueBuilder()->buildMessage($task);
        self::assertSame($nodeB, (int) $msg['node_id']);
    }

    public function testFallbackWorkerUrlWhenNoNodesRegistered(): void
    {
        $factory = $this->factory();
        $accounts = $factory->accountRepository();
        $accId = $accounts->create([
            'login' => 'legacy@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '0.50',
        ]);

        $url = $factory->sessionRouter()->resolveWorkerUrl($accId);
        self::assertSame('http://127.0.0.1:3000', $url);
        self::assertNull($accounts->findById($accId)['assigned_node_id']);
    }
}
