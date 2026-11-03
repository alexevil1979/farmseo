<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use DateTimeImmutable;
use Farm\Controllers\TasksController;
use Farm\Http\Request;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\Admission;
use Farm\Services\Governor;
use Farm\Services\ServiceFactory;
use Farm\Support\MemoryRedis;
use Farm\Tests\TestCase;

final class AdmissionTest extends TestCase
{
    private function factory(int $maxChrome = 40): ServiceFactory
    {
        $factory = new ServiceFactory($this->db, [
            'internal_token' => 'test-internal',
            'app_key' => 'test-key',
            'root' => dirname(__DIR__, 2),
            'redis_memory' => true,
            'governor' => [
                'max_chrome' => $maxChrome,
                'enqueue_batch' => 100,
                'account_limit' => 1,
                'proxy_limit' => 1,
                'admit_backoff_sec' => 5,
                'cpu_threshold' => 80.0,
                'ram_threshold' => 90.0,
            ],
        ]);
        $factory->useMemoryRedis(new MemoryRedis());
        $factory->governor()->setLoadOverride(5.0, 5.0);
        $factory->redis()->set(Governor::CFG_MAX_CHROME, (string) $maxChrome);

        return $factory;
    }

    public function testCanAdmitReturnsDeferWhenChromeCapHit(): void
    {
        $factory = $this->factory(2);
        $accounts = new AccountRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);
        $tasks = new TaskRepository($this->db);

        $scId = $scenarios->create([
            'name' => 'adm-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);

        for ($i = 0; $i < 2; $i++) {
            $accId = $accounts->create([
                'login' => "adm-run-{$i}@test",
                'password_enc' => 'x',
                'status' => 'ready',
                'trust_score' => '1.00',
            ]);
            $tasks->create([
                'type' => 'warmup',
                'priority' => 1,
                'status' => 'running',
                'account_id' => $accId,
                'scenario_id' => $scId,
                'payload' => [
                    'type' => 'warmup',
                    'account_id' => $accId,
                    'scenario_id' => $scId,
                    'limits' => [],
                ],
                'attempts' => 1,
                'max_attempts' => 3,
                'timeout' => 60,
                'started_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        $decision = $factory->admission()->canAdmit(null, null, 1);
        self::assertSame(Admission::DEFER, $decision['decision']);
        self::assertSame(Admission::REASON_CHROME_CAP, $decision['reason']);
    }

    public function testApiBatchRespectsMaxChromeAndSchedulerPicksUpDeferred(): void
    {
        $maxChrome = 40;
        $factory = $this->factory($maxChrome);
        $accounts = new AccountRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);
        $tasks = new TaskRepository($this->db);

        $scId = $scenarios->create([
            'name' => 'adm-batch-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);

        $accountIds = [];
        for ($i = 0; $i < 80; $i++) {
            $accountIds[] = $accounts->create([
                'login' => "batch-acc-{$i}@test",
                'password_enc' => 'x',
                'status' => 'ready',
                'trust_score' => '1.00',
            ]);
        }

        $batch = [];
        for ($i = 0; $i < 500; $i++) {
            $accId = $accountIds[$i % count($accountIds)];
            $batch[] = [
                'type' => 'warmup',
                'account_id' => $accId,
                'scenario_id' => $scId,
                'priority' => 500 - $i,
                'payload' => [
                    'type' => 'warmup',
                    'account_id' => $accId,
                    'scenario_id' => $scId,
                    'limits' => [],
                ],
            ];
        }

        $controller = new TasksController($factory);
        $request = new Request(
            'POST',
            '/api/v1/tasks',
            [],
            [],
            $batch,
            '',
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $response = $controller->store($request);
        self::assertSame(201, $response->status());
        $body = $response->body();
        self::assertIsArray($body);
        self::assertArrayHasKey('meta', $body);
        self::assertSame(500, $body['meta']['created']);
        self::assertLessThanOrEqual($maxChrome, $body['meta']['accepted']);
        self::assertGreaterThan(0, $body['meta']['deferred']);
        self::assertSame(
            500,
            $body['meta']['accepted'] + $body['meta']['deferred']
        );

        $inflight = $tasks->countInflight();
        self::assertLessThanOrEqual($maxChrome, $inflight);
        self::assertSame($body['meta']['accepted'], $inflight);

        $pending = $tasks->count(['status' => 'pending']);
        self::assertSame($body['meta']['deferred'], $pending);

        // No path may exceed chrome cap in the Redis queue either.
        self::assertLessThanOrEqual($maxChrome, $factory->redis()->llen('farm:q:tasks'));

        // Finish all admitted work → free slots.
        foreach ($tasks->findByStatus('queued') as $row) {
            $tasks->update((int) $row['id'], [
                'status' => 'done',
                'finished_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }
        // Drain queue messages for finished tasks (worker would ACK).
        while ($factory->redis()->rpop('farm:q:tasks') !== null) {
        }

        self::assertSame(0, $tasks->countInflight());

        // Make deferred tasks due (backoff may have pushed scheduled_at forward).
        $now = new DateTimeImmutable('now');
        $this->db->execute(
            'UPDATE `tasks` SET `scheduled_at` = :now WHERE `status` = \'pending\'',
            [':now' => $now->format('Y-m-d H:i:s.v')]
        );

        $stats = $factory->scheduler()->run($now);
        self::assertGreaterThan(0, $stats['enqueued']);
        self::assertLessThanOrEqual($maxChrome, $tasks->countInflight());
        self::assertLessThanOrEqual($maxChrome, $factory->redis()->llen('farm:q:tasks'));
    }

    public function testEnqueueNeverBypassesAdmission(): void
    {
        $factory = $this->factory(1);
        $accounts = new AccountRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);
        $tasks = new TaskRepository($this->db);

        $accA = $accounts->create([
            'login' => 'bypass-a@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
        ]);
        $accB = $accounts->create([
            'login' => 'bypass-b@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
        ]);
        $scId = $scenarios->create([
            'name' => 'bypass-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);

        $id1 = $tasks->create([
            'type' => 'warmup',
            'priority' => 10,
            'status' => 'pending',
            'account_id' => $accA,
            'scenario_id' => $scId,
            'payload' => ['type' => 'warmup', 'account_id' => $accA, 'scenario_id' => $scId, 'limits' => []],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 60,
        ]);
        $id2 = $tasks->create([
            'type' => 'warmup',
            'priority' => 5,
            'status' => 'pending',
            'account_id' => $accB,
            'scenario_id' => $scId,
            'payload' => ['type' => 'warmup', 'account_id' => $accB, 'scenario_id' => $scId, 'limits' => []],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 60,
        ]);

        $builder = $factory->taskQueueBuilder();
        $r1 = $builder->enqueue($tasks->findById($id1));
        self::assertSame(Admission::ALLOW, $r1['decision']);
        self::assertSame(1, $factory->redis()->llen('farm:q:tasks'));

        $r2 = $builder->enqueue($tasks->findById($id2));
        self::assertSame(Admission::DEFER, $r2['decision']);
        self::assertSame(Admission::REASON_CHROME_CAP, $r2['defer_reason']);
        self::assertSame('pending', $tasks->findById($id2)['status']);
        self::assertSame(1, $factory->redis()->llen('farm:q:tasks'));
        self::assertNotNull($r2['scheduled_at']);
    }
}
