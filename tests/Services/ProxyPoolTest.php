<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\ProxyAssignmentRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Services\GeoMapper;
use Farm\Services\ProxyPool;
use Farm\Support\MemoryLock;
use Farm\Tests\TestCase;

final class ProxyPoolTest extends TestCase
{
    private ProxyPool $pool;
    private ProxyRepository $proxies;
    private AccountRepository $accounts;
    private ProxyAssignmentRepository $assignments;

    protected function setUp(): void
    {
        parent::setUp();
        MemoryLock::reset();
        $this->proxies = new ProxyRepository($this->db);
        $this->accounts = new AccountRepository($this->db);
        $this->assignments = new ProxyAssignmentRepository($this->db);
        $this->pool = new ProxyPool(
            $this->db,
            $this->proxies,
            $this->assignments,
            $this->accounts,
            new MemoryLock(),
            new GeoMapper(),
        );
    }

    public function testImportHostPortUserPass(): void
    {
        $lines = [];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = sprintf('10.0.%d.%d:%d:user%d:pass%d', intdiv($i, 256), $i % 256, 10000 + $i, $i, $i);
        }

        $result = $this->pool->importLines($lines, 'http', 'RU');
        self::assertSame(100, $result['imported']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
        self::assertSame(100, $this->proxies->count());

        $again = $this->pool->importLines($lines, 'http', 'RU');
        self::assertSame(0, $again['imported']);
        self::assertSame(100, $again['skipped']);
    }

    public function testStickyAssignAndSingleProxyConstraint(): void
    {
        $p1 = $this->proxies->create([
            'host' => '1.1.1.1', 'port' => 8080, 'protocol' => 'http',
            'country' => 'RU', 'health' => 'ok', 'is_active' => 1, 'latency_ms' => 100,
        ]);
        $p2 = $this->proxies->create([
            'host' => '2.2.2.2', 'port' => 8080, 'protocol' => 'http',
            'country' => 'RU', 'health' => 'ok', 'is_active' => 1, 'latency_ms' => 50,
        ]);
        $acc = $this->accounts->create([
            'login' => 'a@test', 'password_enc' => 'x', 'status' => 'new', 'trust_score' => '0.00',
        ]);

        $first = $this->pool->assign($acc);
        self::assertFalse($first['sticky']);
        self::assertContains($first['proxy_id'], [$p1, $p2]);

        $second = $this->pool->assign($acc);
        self::assertTrue($second['sticky']);
        self::assertSame($first['proxy_id'], $second['proxy_id']);

        $currents = $this->db->fetchAll(
            'SELECT * FROM proxy_assignments WHERE account_id = ? AND is_current = 1',
            [$acc]
        );
        self::assertCount(1, $currents);
        self::assertSame($first['proxy_id'], (int) $this->accounts->findById($acc)['proxy_id']);
    }

    public function testRotateDeadKeepsSameGeo(): void
    {
        $dead = $this->proxies->create([
            'host' => '9.9.9.9', 'port' => 1, 'protocol' => 'http',
            'country' => 'DE', 'health' => 'dead', 'is_active' => 1,
        ]);
        $liveDe = $this->proxies->create([
            'host' => '8.8.8.8', 'port' => 1, 'protocol' => 'http',
            'country' => 'DE', 'health' => 'ok', 'is_active' => 1, 'latency_ms' => 40,
        ]);
        $liveRu = $this->proxies->create([
            'host' => '7.7.7.7', 'port' => 1, 'protocol' => 'http',
            'country' => 'RU', 'health' => 'ok', 'is_active' => 1, 'latency_ms' => 10,
        ]);
        $acc = $this->accounts->create([
            'login' => 'rot@test', 'password_enc' => 'x', 'status' => 'active', 'trust_score' => '1.00',
            'proxy_id' => $dead,
        ]);
        $this->assignments->assign($acc, $dead);

        $results = $this->pool->rotateDead();
        self::assertCount(1, $results);
        self::assertTrue($results[0]['ok']);
        self::assertSame($dead, $results[0]['old_proxy_id']);
        self::assertSame($liveDe, $results[0]['new_proxy_id']);
        self::assertNotSame($liveRu, $results[0]['new_proxy_id']);

        $current = $this->assignments->findCurrentByAccount($acc);
        self::assertSame($liveDe, (int) $current['proxy_id']);
        self::assertSame($liveDe, (int) $this->accounts->findById($acc)['proxy_id']);
    }

    public function testAccountCannotHaveTwoCurrentProxies(): void
    {
        $p1 = $this->proxies->create([
            'host' => '1.0.0.1', 'port' => 80, 'protocol' => 'http',
            'country' => 'US', 'health' => 'ok', 'is_active' => 1,
        ]);
        $p2 = $this->proxies->create([
            'host' => '1.0.0.2', 'port' => 80, 'protocol' => 'http',
            'country' => 'US', 'health' => 'ok', 'is_active' => 1,
        ]);
        $acc = $this->accounts->create([
            'login' => 'one@test', 'password_enc' => 'x', 'status' => 'new', 'trust_score' => '0.00',
        ]);

        $this->pool->assign($acc, ['force' => true]);
        $this->pool->assign($acc, ['force' => true]);

        $currents = $this->db->fetchAll(
            'SELECT proxy_id FROM proxy_assignments WHERE account_id = ? AND is_current = 1',
            [$acc]
        );
        self::assertCount(1, $currents);
    }

    public function testGeoMapper(): void
    {
        $mapper = new GeoMapper();
        $ru = $mapper->map(['country' => 'ru', 'timezone' => 'Europe/Moscow', 'city' => 'Moscow']);
        self::assertSame('RU', $ru['country']);
        self::assertSame('Europe/Moscow', $ru['timezone']);
        self::assertSame('ru-RU', $ru['locale']);
        self::assertContains('ru', $ru['languages']);
    }
}
