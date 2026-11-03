<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AlertRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\AlertEngine;
use Farm\Services\Governor;
use Farm\Services\Telegram;
use Farm\Support\RedisClient;
use Farm\Tests\TestCase;

final class AlertEngineTest extends TestCase
{
    private AlertRepository $alerts;
    private ProxyRepository $proxies;
    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $tgCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        AlertEngine::resetMemoryKnownWorkers();
        $this->alerts = new AlertRepository($this->db);
        $this->proxies = new ProxyRepository($this->db);
        $this->tgCalls = [];
    }

    public function testWorkerDownFiresWithinNextTick(): void
    {
        $engine = $this->engine();
        $engine->setAliveWorkersOverride(['w-1', 'w-2']);
        $first = $engine->evaluate();
        self::assertSame([], $first['workers_down']);
        self::assertSame([], $first['fired']);

        $engine->setAliveWorkersOverride(['w-1']);
        $second = $engine->evaluate();
        self::assertSame(['w-2'], $second['workers_down']);
        self::assertCount(1, $second['fired']);
        self::assertSame('worker_down', $second['fired'][0]['rule']);
        self::assertSame('critical', $second['fired'][0]['severity']);
        self::assertCount(1, $this->alerts->findUnacked());
        self::assertNotEmpty($this->tgCalls);
    }

    public function testProxyDeadOver20PercentFires(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->proxies->create([
                'host' => "10.0.0.{$i}",
                'port' => 10000 + $i,
                'protocol' => 'http',
                'health' => $i <= 3 ? 'dead' : 'ok',
                'is_active' => 1,
            ]);
        }

        $engine = $this->engine();
        $engine->setAliveWorkersOverride([]);
        $alert = $engine->ruleProxyDegraded();
        self::assertNotNull($alert);
        self::assertSame('proxy_degraded', $alert['meta']['rule']);
        self::assertGreaterThan(20.0, $alert['meta']['dead_pct']);

        $result = $engine->evaluate();
        $rules = array_column($result['fired'], 'rule');
        self::assertContains('proxy_degraded', $rules);
    }

    public function testProxyDeadAtOrBelow20PercentSilent(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->proxies->create([
                'host' => "10.1.0.{$i}",
                'port' => 11000 + $i,
                'protocol' => 'http',
                'health' => $i <= 2 ? 'dead' : 'ok',
                'is_active' => 1,
            ]);
        }

        $engine = $this->engine();
        self::assertNull($engine->ruleProxyDegraded());
    }

    public function testLoadHighFiresOnCpu(): void
    {
        $engine = $this->engine(['cpu_threshold' => 80.0, 'ram_threshold' => 90.0]);
        $alert = $engine->ruleLoadHigh(['cpu' => 95.0, 'ram' => 10.0, 'disk' => 10.0]);
        self::assertNotNull($alert);
        self::assertSame('load_high', $alert['meta']['rule']);
        self::assertSame('warn', $alert['severity']);
    }

    public function testCaptchaErrorsThreshold(): void
    {
        $tasks = new TaskRepository($this->db);
        for ($i = 0; $i < 5; $i++) {
            $tasks->create([
                'type' => 'warmup',
                'priority' => 0,
                'status' => 'failed',
                'payload' => ['type' => 'warmup', 'account_id' => 0, 'scenario_id' => 0, 'limits' => []],
                'attempts' => 1,
                'max_attempts' => 3,
                'timeout' => 60,
                'error' => 'captcha solve failed',
                'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        $engine = $this->engine(['captcha_threshold' => 5, 'captcha_window_sec' => 300]);
        $alert = $engine->ruleCaptchaErrors();
        self::assertNotNull($alert);
        self::assertSame('captcha_errors', $alert['meta']['rule']);
    }

    /**
     * @param array<string, mixed> $thresholds
     */
    private function engine(array $thresholds = []): AlertEngine
    {
        $tg = new Telegram(
            null,
            null,
            'test-token',
            '12345',
            300,
            function (string $method, array $params): array {
                $this->tgCalls[] = ['method' => $method, 'params' => $params];

                return ['ok' => true, 'result' => ['message_id' => 1]];
            }
        );

        $gov = new Governor(
            new RedisClient(['host' => '127.0.0.1', 'port' => 1]),
            new TaskRepository($this->db),
            null,
            40,
            80.0,
            90.0,
        );
        $gov->setLoadOverride(10.0, 10.0);

        return new AlertEngine(
            $this->alerts,
            $tg,
            $this->proxies,
            $gov,
            null,
            $this->db,
            $thresholds,
        );
    }
}
