<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\Governor;
use Farm\Support\RedisClient;
use Farm\Tests\TestCase;

final class GovernorTest extends TestCase
{
    public function testChromeCapBlocksWhenAtLimit(): void
    {
        $tasks = new TaskRepository($this->db);
        $accounts = new AccountRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);

        $accId = $accounts->create([
            'login' => 'gov@test',
            'password_enc' => 'x',
            'status' => 'active',
            'trust_score' => '1.00',
        ]);
        $scId = $scenarios->create([
            'name' => 'gov-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);

        // Fill to max_chrome=2 with running tasks
        for ($i = 0; $i < 2; $i++) {
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
                'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        $gov = new Governor(
            new RedisClient(['host' => '127.0.0.1', 'port' => 1]),
            $tasks,
            null,
            2,
            80.0,
            90.0,
        );
        $gov->setLoadOverride(10.0, 10.0);

        self::assertSame(2, $gov->liveChrome());
        self::assertFalse($gov->canAdmit(1));
        self::assertSame(0, $gov->admitBudget());
    }

    public function testCpuThrottleBlocksAndRecovers(): void
    {
        $tasks = new TaskRepository($this->db);
        $gov = new Governor(
            new RedisClient(['host' => '127.0.0.1', 'port' => 1]),
            $tasks,
            null,
            40,
            80.0,
            90.0,
        );
        $gov->setLiveChromeOverride(0);

        $gov->setLoadOverride(85.0, 20.0);
        self::assertFalse($gov->canAdmit(1));
        self::assertSame('cpu_throttle', $gov->status()['reason']);

        // Counter / throttle recovers when load drops — no sticky flag
        $gov->setLoadOverride(40.0, 20.0);
        self::assertTrue($gov->canAdmit(1));
        self::assertFalse($gov->status()['throttled']);
    }
}
