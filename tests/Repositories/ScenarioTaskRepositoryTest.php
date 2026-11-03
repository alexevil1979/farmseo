<?php

declare(strict_types=1);

namespace Farm\Tests\Repositories;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\CampaignRepository;
use Farm\Repositories\KeywordRepository;
use Farm\Repositories\RunsEventRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\ScenarioStepRepository;
use Farm\Repositories\TargetRepository;
use Farm\Repositories\TaskRepository;
use Farm\Repositories\TaskRunRepository;
use Farm\Tests\TestCase;

final class ScenarioTaskRepositoryTest extends TestCase
{
    public function testScenarioStepsAndTasksLifecycle(): void
    {
        $scenarios = new ScenarioRepository($this->db);
        $steps = new ScenarioStepRepository($this->db);
        $accounts = new AccountRepository($this->db);
        $tasks = new TaskRepository($this->db);
        $runs = new TaskRunRepository($this->db);
        $events = new RunsEventRepository($this->db);

        $scenarioId = $scenarios->create([
            'name' => 'PF Desktop',
            'type' => 'pf',
            'version' => 1,
            'defaults' => ['limits' => []],
        ]);
        self::assertSame($scenarioId, (int) $scenarios->findByNameVersion('PF Desktop', 1)['id']);

        $steps->create([
            'scenario_id' => $scenarioId,
            'step_order' => 1,
            'action' => 'navigate',
            'params' => ['url' => 'https://example.com'],
        ]);
        $steps->create([
            'scenario_id' => $scenarioId,
            'step_order' => 2,
            'action' => 'scroll',
            'params' => ['times' => 2],
        ]);
        $ordered = $steps->findByScenarioOrdered($scenarioId);
        self::assertCount(2, $ordered);
        self::assertSame(1, (int) $ordered[0]['step_order']);

        $accId = $accounts->create([
            'login' => 'task@test',
            'password_enc' => 'enc',
            'status' => 'ready',
            'trust_score' => '5.00',
        ]);

        $taskId = $tasks->create([
            'type' => 'pf',
            'priority' => 5,
            'status' => 'pending',
            'account_id' => $accId,
            'scenario_id' => $scenarioId,
            'payload' => [
                'type' => 'pf',
                'account_id' => $accId,
                'scenario_id' => $scenarioId,
                'target_url' => 'https://example.com',
                'keyword' => 'test',
                'geo' => 'ru',
                'device' => 'desktop',
                'limits' => [],
            ],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 300,
        ]);

        $due = $tasks->findPendingDue();
        self::assertNotEmpty($due);
        self::assertTrue($tasks->claim($taskId, 'queued'));
        self::assertFalse($tasks->claim($taskId, 'queued'));

        $runId = $runs->create([
            'task_id' => $taskId,
            'attempt' => 1,
            'worker_id' => 'w1',
            'status' => 'running',
            'metrics' => ['pages' => 1],
            'screenshots' => [],
        ]);
        self::assertSame($runId, (int) $runs->findByTaskAttempt($taskId, 1)['id']);

        $eid = $events->append($runId, 'step_ok', 'info', 'navigated', ['step' => 1]);
        self::assertGreaterThan(0, $eid);
        self::assertCount(1, $events->findByTaskRun($runId));

        self::assertTrue($tasks->markFinished($taskId, 'done'));
        self::assertSame('done', $tasks->findById($taskId)['status']);
    }

    public function testCampaignKeywordTarget(): void
    {
        $scenarios = new ScenarioRepository($this->db);
        $campaigns = new CampaignRepository($this->db);
        $keywords = new KeywordRepository($this->db);
        $targets = new TargetRepository($this->db);

        $sid = $scenarios->create(['name' => 'Warmup', 'type' => 'warmup', 'version' => 1]);
        $cid = $campaigns->create([
            'name' => 'Demo Campaign',
            'status' => 'active',
            'scenario_id' => $sid,
            'budget_tasks' => 100,
        ]);
        self::assertSame($cid, (int) $campaigns->findByName('Demo Campaign')['id']);

        $keywords->create([
            'campaign_id' => $cid,
            'phrase' => 'купить слона',
            'lang' => 'ru',
            'geo' => 'ru',
            'priority' => 1,
        ]);
        $targets->create([
            'campaign_id' => $cid,
            'url' => 'https://example.com/product',
            'domain' => 'example.com',
            'title' => 'Product',
        ]);

        self::assertCount(1, $keywords->findActiveByCampaign($cid));
        self::assertCount(1, $targets->findByDomain('example.com'));
        self::assertCount(1, $campaigns->findByStatus('active'));
    }
}
