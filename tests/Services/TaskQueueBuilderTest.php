<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\FingerprintGenerator;
use Farm\Services\TaskQueueBuilder;
use Farm\Support\Crypto;
use Farm\Tests\TestCase;

final class TaskQueueBuilderTest extends TestCase
{
    public function testHydratesProxyAndFingerprint(): void
    {
        $crypto = new Crypto('test-key');
        $proxies = new ProxyRepository($this->db);
        $accounts = new AccountRepository($this->db);
        $fps = new AccountFingerprintRepository($this->db);
        $scenarios = new ScenarioRepository($this->db);
        $tasks = new TaskRepository($this->db);

        $proxyId = $proxies->create([
            'host' => '10.0.0.1',
            'port' => 8080,
            'protocol' => 'http',
            'username' => 'u',
            'password_enc' => $crypto->encrypt('secret'),
            'health' => 'ok',
            'is_active' => 1,
        ]);

        $accId = $accounts->create([
            'login' => 'hydrate@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '1.00',
            'proxy_id' => $proxyId,
        ]);

        $gen = new FingerprintGenerator();
        $fp = $gen->generate(['country' => 'RU', 'seed' => 'tqb-1']);
        $fps->upsertForAccount($accId, $gen->toRow($accId, $fp));

        $scId = $scenarios->create([
            'name' => 'hydrate-sc',
            'type' => 'pf',
            'version' => 1,
            'defaults' => [],
        ]);

        $taskId = $tasks->create([
            'type' => 'pf',
            'priority' => 5,
            'status' => 'queued',
            'account_id' => $accId,
            'scenario_id' => $scId,
            'payload' => [
                'type' => 'pf',
                'account_id' => $accId,
                'scenario_id' => $scId,
                'target_url' => 'https://example.com',
                'keyword' => 'test',
                'limits' => [],
            ],
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout' => 120,
        ]);

        $builder = new TaskQueueBuilder($accounts, $proxies, $fps, $crypto, $gen);
        $msg = $builder->buildMessage($tasks->findById($taskId));

        self::assertSame($taskId, $msg['task_id']);
        self::assertSame($accId, $msg['account_id']);
        self::assertIsArray($msg['proxy']);
        self::assertSame('10.0.0.1', $msg['proxy']['host']);
        self::assertSame(8080, $msg['proxy']['port']);
        self::assertSame('secret', $msg['proxy']['password']);
        self::assertIsArray($msg['fingerprint']);
        self::assertNotEmpty($msg['fingerprint']['ua'] ?? $msg['fingerprint']['hash'] ?? null);
    }
}
