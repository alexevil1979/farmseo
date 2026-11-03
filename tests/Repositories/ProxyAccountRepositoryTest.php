<?php

declare(strict_types=1);

namespace Farm\Tests\Repositories;

use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\AccountSessionRepository;
use Farm\Repositories\ProxyAssignmentRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Tests\TestCase;

final class ProxyAccountRepositoryTest extends TestCase
{
    public function testProxyUniqueAndAccountLink(): void
    {
        $proxies = new ProxyRepository($this->db);
        $accounts = new AccountRepository($this->db);

        $proxyId = $proxies->create([
            'host' => '10.0.0.1',
            'port' => 8080,
            'protocol' => 'http',
            'password_enc' => base64_encode('secret'),
            'health' => 'ok',
            'is_active' => 1,
            'meta' => ['region' => 'eu'],
        ]);

        $dup = $proxies->createIgnore([
            'host' => '10.0.0.1',
            'port' => 8080,
            'protocol' => 'http',
            'password_enc' => 'x',
        ]);
        self::assertNull($dup);

        $found = $proxies->findByHostPortProtocol('10.0.0.1', 8080, 'http');
        self::assertSame($proxyId, (int) $found['id']);
        self::assertCount(1, $proxies->findHealthy());

        $accId = $accounts->create([
            'login' => 'user1@test',
            'password_enc' => base64_encode('pass'),
            'status' => 'new',
            'trust_score' => '12.50',
            'proxy_id' => $proxyId,
            'tags' => ['a', 'b'],
        ]);

        self::assertSame('user1@test', $accounts->findByLogin('user1@test')['login']);
        self::assertTrue($accounts->updateStatus($accId, 'ready'));
        self::assertTrue($accounts->updateTrustScore($accId, '99.99'));
        self::assertSame('99.99', $accounts->findById($accId)['trust_score']);
        self::assertCount(1, $accounts->findByProxyId($proxyId));
    }

    public function testFingerprintUpsertSessionsAndAssignments(): void
    {
        $proxies = new ProxyRepository($this->db);
        $accounts = new AccountRepository($this->db);
        $fps = new AccountFingerprintRepository($this->db);
        $sessions = new AccountSessionRepository($this->db);
        $assign = new ProxyAssignmentRepository($this->db);

        $p1 = $proxies->create(['host' => '1.1.1.1', 'port' => 1000, 'protocol' => 'http', 'password_enc' => 'a']);
        $p2 = $proxies->create(['host' => '2.2.2.2', 'port' => 1000, 'protocol' => 'socks5', 'password_enc' => 'b']);
        $accId = $accounts->create([
            'login' => 'fp@test',
            'password_enc' => 'enc',
            'status' => 'active',
            'trust_score' => '1.00',
        ]);

        $fpId = $fps->upsertForAccount($accId, [
            'user_agent' => 'Mozilla/5.0',
            'platform' => 'Win32',
            'viewport_w' => 1920,
            'viewport_h' => 1080,
        ]);
        $fpId2 = $fps->upsertForAccount($accId, [
            'user_agent' => 'Mozilla/5.0 Chrome',
            'platform' => 'Win32',
        ]);
        self::assertSame($fpId, $fpId2);
        self::assertSame('Mozilla/5.0 Chrome', $fps->findByAccountId($accId)['user_agent']);

        $sid = $sessions->create([
            'account_id' => $accId,
            'status' => 'active',
            'vnc_port' => 5901,
        ]);
        self::assertNotEmpty($sessions->findActiveByAccount($accId));
        self::assertTrue($sessions->endSession($sid));

        $a1 = $assign->assign($accId, $p1);
        $a2 = $assign->assign($accId, $p2);
        self::assertNotSame($a1, $a2);
        $current = $assign->findCurrentByAccount($accId);
        self::assertSame($p2, (int) $current['proxy_id']);
        self::assertSame(1, $assign->count(['account_id' => $accId, 'is_current' => 1]));
    }
}
