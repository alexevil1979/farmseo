<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\TaskRepository;
use Farm\Services\RetryPolicy;
use Farm\Tests\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testBackoffGrowsAndCaps(): void
    {
        $policy = new RetryPolicy(
            new TaskRepository($this->db),
            baseDelaySec: 30,
            maxDelaySec: 120,
            multiplier: 2.0,
            jitterRatio: 0.0,
        );

        self::assertSame(30, $policy->delaySeconds(1));
        self::assertSame(60, $policy->delaySeconds(2));
        self::assertSame(120, $policy->delaySeconds(3));
        self::assertSame(120, $policy->delaySeconds(10));
    }

    public function testApplyFailureRetriesThenFails(): void
    {
        $tasks = new TaskRepository($this->db);
        $accounts = new \Farm\Repositories\AccountRepository($this->db);
        $scenarios = new \Farm\Repositories\ScenarioRepository($this->db);

        $accId = $accounts->create([
            'login' => 'retry@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
        ]);
        $scId = $scenarios->create([
            'name' => 'retry-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);
        $taskId = $tasks->create([
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
            'attempts' => 0,
            'max_attempts' => 2,
            'timeout' => 60,
        ]);

        $policy = new RetryPolicy($tasks, 1, 5, 2.0, 0.0);
        $task = $tasks->findById($taskId);
        self::assertNotNull($task);

        $r1 = $policy->applyFailure($task, 'boom1');
        self::assertSame('retrying', $r1['status']);
        self::assertSame(1, $r1['attempts']);

        $task = $tasks->findById($taskId);
        $r2 = $policy->applyFailure($task, 'boom2');
        self::assertSame('failed', $r2['status']);
        self::assertSame(2, $r2['attempts']);

        $final = $tasks->findById($taskId);
        self::assertSame('failed', $final['status']);
    }
}
