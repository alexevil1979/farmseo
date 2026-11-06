<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\AccountSessionRepository;
use Farm\Services\LiveView;
use Farm\Tests\TestCase;

final class LiveViewTest extends TestCase
{
    public function testStreamUrlIsPanelProxied(): void
    {
        $accounts = new AccountRepository($this->db);
        $sessions = new AccountSessionRepository($this->db);
        $live = new LiveView(
            $accounts,
            $sessions,
            null,
            'http://127.0.0.1:3000',
            'tok',
            5900,
            600,
        );

        self::assertSame(
            '/live/42/vnc.html?autoconnect=1&resize=scale&reconnect=true&path=live%2F42%2F',
            $live->streamUrl(42)
        );
    }

    public function testBusyPortsFromActiveSessions(): void
    {
        $accounts = new AccountRepository($this->db);
        $sessions = new AccountSessionRepository($this->db);
        $accId = $accounts->create([
            'login' => 'live@test',
            'password_enc' => 'x',
            'status' => 'active',
            'trust_score' => '1.00',
        ]);
        $sessions->create([
            'account_id' => $accId,
            'vnc_port' => 5903,
            'novnc_url' => '/live/' . $accId . '/vnc.html',
            'status' => 'active',
            'meta' => ['ws_port' => 6083],
        ]);

        $live = new LiveView(
            $accounts,
            $sessions,
            null,
            'http://127.0.0.1:3000',
            'tok',
        );

        self::assertSame([5903, 6083], $live->busyPorts());
    }

    public function testStopIdleEndsStaleSessionWithoutWorker(): void
    {
        $accounts = new AccountRepository($this->db);
        $sessions = new AccountSessionRepository($this->db);
        $accId = $accounts->create([
            'login' => 'idle@test',
            'password_enc' => 'x',
            'status' => 'active',
            'trust_score' => '1.00',
        ]);
        $old = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s.v');
        $sessions->create([
            'account_id' => $accId,
            'vnc_port' => 5910,
            'novnc_url' => '/live/' . $accId . '/vnc.html',
            'status' => 'active',
            'started_at' => $old,
            'meta' => ['ws_port' => 6090],
        ]);

        $live = new LiveView(
            $accounts,
            $sessions,
            null,
            'http://127.0.0.1:9',
            'tok',
            5900,
            60,
            1,
        );

        self::assertNotEmpty($sessions->findActiveByAccount($accId));
        $stopped = $live->stopIdle(time());
        self::assertContains($accId, $stopped);
        self::assertSame([], $sessions->findActiveByAccount($accId));
    }

    public function testLatestScreenshotFindsAccountFile(): void
    {
        $accounts = new AccountRepository($this->db);
        $sessions = new AccountSessionRepository($this->db);
        $accId = $accounts->create([
            'login' => 'shot@test',
            'password_enc' => 'x',
            'status' => 'ready',
            'trust_score' => '0.50',
        ]);

        $root = dirname(__DIR__, 2);
        $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'screenshots';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'account_' . $accId . '_test.png';
        file_put_contents($file, 'png');

        $live = new LiveView($accounts, $sessions, null, 'http://127.0.0.1:9', 'tok');
        $found = $live->latestScreenshot($accId, $root);
        @unlink($file);

        self::assertNotNull($found);
        self::assertSame($accId, $found['account_id']);
        self::assertStringContainsString('account_' . $accId, $found['filename']);
    }
}
