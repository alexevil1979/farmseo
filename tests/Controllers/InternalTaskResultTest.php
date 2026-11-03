<?php

declare(strict_types=1);

namespace Farm\Tests\Controllers;

use Farm\Controllers\InternalController;
use Farm\Http\Request;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\ServiceFactory;
use Farm\Tests\TestCase;

final class InternalTaskResultTest extends TestCase
{
    private function factory(): ServiceFactory
    {
        return new ServiceFactory($this->db, [
            'internal_token' => 'test-internal',
            'app_key' => 'test-key',
            'root' => dirname(__DIR__, 2),
            'governor' => ['max_chrome' => 10, 'enqueue_batch' => 10],
        ]);
    }

    private function makeTask(): array
    {
        $accounts = new AccountRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);
        $tasks = new TaskRepository($this->db);

        $accId = $accounts->create([
            'login' => 'result@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
        ]);
        $scId = $scenarios->create([
            'name' => 'result-sc',
            'type' => 'warmup',
            'version' => 1,
            'defaults' => [],
        ]);
        $taskId = $tasks->create([
            'type' => 'warmup',
            'priority' => 1,
            'status' => 'queued',
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

        return $tasks->findById($taskId);
    }

    private function request(array $body): Request
    {
        return new Request(
            'POST',
            '/internal/task/result',
            [],
            [],
            $body,
            '',
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
    }

    public function testRunningSetsStartedAtAndStatus(): void
    {
        $task = $this->makeTask();
        $ctrl = new InternalController($this->factory());
        $res = $ctrl->taskResult($this->request([
            'task_id' => (int) $task['id'],
            'status' => 'running',
            'error' => null,
            'metrics' => [],
            'screenshots' => [],
            'attempt' => 1,
            'worker_id' => 'w1',
        ]));
        self::assertSame(200, $res->status());
        $fresh = (new TaskRepository($this->db))->findById((int) $task['id']);
        self::assertSame('running', $fresh['status']);
        self::assertNotEmpty($fresh['started_at']);
    }

    public function testFailedAppliesRetryPolicy(): void
    {
        $task = $this->makeTask();
        $tasks = new TaskRepository($this->db);
        $tasks->update((int) $task['id'], ['status' => 'running', 'started_at' => date('Y-m-d H:i:s.v')]);

        $ctrl = new InternalController($this->factory());
        $res = $ctrl->taskResult($this->request([
            'task_id' => (int) $task['id'],
            'status' => 'failed',
            'error' => 'boom',
            'metrics' => [],
            'screenshots' => [],
        ]));
        self::assertSame(200, $res->status());
        $body = $res->body();
        self::assertSame('retrying', $body['status']);

        $fresh = $tasks->findById((int) $task['id']);
        self::assertSame('retrying', $fresh['status']);
        self::assertSame(1, (int) $fresh['attempts']);
        self::assertNotEmpty($fresh['scheduled_at']);
    }

    public function testFailedExhaustsToTerminal(): void
    {
        $task = $this->makeTask();
        $tasks = new TaskRepository($this->db);
        $tasks->update((int) $task['id'], [
            'status' => 'running',
            'attempts' => 1,
            'max_attempts' => 2,
            'started_at' => date('Y-m-d H:i:s.v'),
        ]);

        $ctrl = new InternalController($this->factory());
        $ctrl->taskResult($this->request([
            'task_id' => (int) $task['id'],
            'status' => 'failed',
            'error' => 'boom2',
            'metrics' => [],
            'screenshots' => [],
        ]));

        $fresh = $tasks->findById((int) $task['id']);
        self::assertSame('failed', $fresh['status']);
        self::assertSame(2, (int) $fresh['attempts']);
    }
}
