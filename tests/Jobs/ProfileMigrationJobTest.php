<?php

declare(strict_types=1);

namespace Farm\Tests\Jobs;

use Farm\Jobs\ProfileMigrationJob;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ProfileMigrationRepository;
use Farm\Services\ProfileMigration\LocalFsTransport;
use Farm\Services\ServiceFactory;
use Farm\Support\MemoryRedis;
use Farm\Tests\TestCase;

final class ProfileMigrationJobTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'farm-pm-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            $this->rmTree($this->tmpRoot);
        }
        parent::tearDown();
    }

    public function testNodeDeathMovesProfileWithCookiesAndClearsSingletons(): void
    {
        $factory = $this->factory();
        $nodes = $factory->nodeRepository();
        $accounts = $factory->accountRepository();

        $nodeA = $nodes->create([
            'address' => 'http://10.0.0.1:3000',
            'capacity' => 10,
            'status' => 'online',
            'meta' => ['profiles_path' => $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeA'],
        ]);
        $nodeB = $nodes->create([
            'address' => 'http://10.0.0.2:3000',
            'capacity' => 10,
            'status' => 'online',
            'meta' => ['profiles_path' => $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeB'],
        ]);

        $accId = $accounts->create([
            'login' => 'migrate@test',
            'password_enc' => 'x',
            'status' => 'error',
            'trust_score' => '88.50',
            'assigned_node_id' => $nodeA,
            'profile_path' => 'storage/profiles/' . 0,
        ]);
        // Fix profile_path after id known
        $accounts->update($accId, ['profile_path' => 'storage/profiles/' . $accId]);

        $src = $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeA' . DIRECTORY_SEPARATOR . $accId;
        mkdir($src . DIRECTORY_SEPARATOR . 'Network', 0775, true);
        file_put_contents($src . DIRECTORY_SEPARATOR . 'Cookies', "cookie-trust-token-{$accId}");
        file_put_contents($src . DIRECTORY_SEPARATOR . 'Network' . DIRECTORY_SEPARATOR . 'Cookies', 'nested-cookie');
        file_put_contents($src . DIRECTORY_SEPARATOR . 'SingletonLock', 'LOCK');
        file_put_contents($src . DIRECTORY_SEPARATOR . 'SingletonCookie', 'SCOOKIE');

        $nodes->markOffline($nodeA);
        $factory->nodeRegistry()->publishHeartbeat($nodeB, [
            'capacity' => 10,
            'load' => 0,
            'address' => 'http://10.0.0.2:3000',
        ]);

        $transport = new LocalFsTransport([
            $nodeA => $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeA',
            $nodeB => $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeB',
        ], $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging');

        $job = $factory->profileMigrationJob($transport);
        $result = $job->migrateAccount($accId, $nodeB);

        self::assertSame('done', $result['status'], json_encode($result));

        $dest = $this->tmpRoot . DIRECTORY_SEPARATOR . 'nodeB' . DIRECTORY_SEPARATOR . $accId;
        self::assertFileExists($dest . DIRECTORY_SEPARATOR . 'Cookies');
        self::assertSame(
            "cookie-trust-token-{$accId}",
            file_get_contents($dest . DIRECTORY_SEPARATOR . 'Cookies')
        );
        self::assertFileExists($dest . DIRECTORY_SEPARATOR . 'Network' . DIRECTORY_SEPARATOR . 'Cookies');
        self::assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'SingletonLock');
        self::assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'SingletonCookie');

        $row = $accounts->findById($accId);
        self::assertSame($nodeB, (int) $row['assigned_node_id']);
        self::assertSame('ready', $row['status']);
        self::assertFalse($factory->accountLocks()->isAccountLocked($accId));

        $marker = $factory->profileMigrationRepository()->findDoneForAccountNode($accId, $nodeB);
        self::assertNotNull($marker);
        self::assertNotEmpty($marker['archive_checksum']);

        // Idempotent re-run must not wipe cookies
        $again = $job->migrateAccount($accId, $nodeB);
        self::assertSame('skipped', $again['status']);
        self::assertSame(
            "cookie-trust-token-{$accId}",
            file_get_contents($dest . DIRECTORY_SEPARATOR . 'Cookies')
        );
    }

    public function testBatchPrioritizesHighTrustAndRespectsMaxConcurrent(): void
    {
        $factory = $this->factory();
        $nodes = $factory->nodeRepository();
        $accounts = $factory->accountRepository();

        $nodeA = $nodes->create([
            'address' => 'http://10.0.0.11:3000',
            'capacity' => 10,
            'status' => 'offline',
        ]);
        $nodeB = $nodes->create([
            'address' => 'http://10.0.0.12:3000',
            'capacity' => 10,
            'status' => 'online',
        ]);
        $factory->nodeRegistry()->publishHeartbeat($nodeB, [
            'capacity' => 10,
            'load' => 0,
            'address' => 'http://10.0.0.12:3000',
        ]);

        $roots = [
            $nodeA => $this->tmpRoot . DIRECTORY_SEPARATOR . 'A',
            $nodeB => $this->tmpRoot . DIRECTORY_SEPARATOR . 'B',
        ];
        mkdir($roots[$nodeA], 0775, true);
        mkdir($roots[$nodeB], 0775, true);

        $low = $this->seedAccountOnNode($accounts, $nodeA, $roots[$nodeA], 'low@t', '10.00');
        $high = $this->seedAccountOnNode($accounts, $nodeA, $roots[$nodeA], 'high@t', '99.00');
        $mid = $this->seedAccountOnNode($accounts, $nodeA, $roots[$nodeA], 'mid@t', '50.00');

        $transport = new LocalFsTransport($roots, $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging');
        $job = new ProfileMigrationJob(
            $accounts,
            $nodes,
            $factory->profileMigrationRepository(),
            $factory->accountLocks(),
            $factory->nodeRegistry(),
            $transport,
            $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging',
            1,
        );

        $batch = $job->run(1);
        self::assertSame(1, $batch['migrated']);
        self::assertSame($high, (int) $batch['results'][0]['account_id']);
        self::assertSame($nodeB, (int) $accounts->findById($high)['assigned_node_id']);
        self::assertSame($nodeA, (int) $accounts->findById($low)['assigned_node_id']);
        self::assertSame($nodeA, (int) $accounts->findById($mid)['assigned_node_id']);

        // Slot free again → next by trust (mid before low)
        $batch2 = $job->run(1);
        self::assertSame(1, $batch2['migrated']);
        self::assertSame($mid, (int) $batch2['results'][0]['account_id']);
    }

    public function testMaxConcurrentSkipsWhenSlotsFull(): void
    {
        $factory = $this->factory();
        $nodes = $factory->nodeRepository();
        $accounts = $factory->accountRepository();
        $migrations = $factory->profileMigrationRepository();

        $nodeA = $nodes->create(['address' => 'http://10.0.0.21:3000', 'capacity' => 5, 'status' => 'offline']);
        $nodeB = $nodes->create(['address' => 'http://10.0.0.22:3000', 'capacity' => 5, 'status' => 'online']);

        $accId = $accounts->create([
            'login' => 'busy@t',
            'password_enc' => 'x',
            'status' => 'error',
            'trust_score' => '70.00',
            'assigned_node_id' => $nodeA,
        ]);

        $migrations->create([
            'account_id' => $accId,
            'from_node_id' => $nodeA,
            'to_node_id' => $nodeB,
            'status' => ProfileMigrationRepository::STATUS_RUNNING,
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        // Another account waiting
        $other = $accounts->create([
            'login' => 'wait@t',
            'password_enc' => 'x',
            'status' => 'error',
            'trust_score' => '90.00',
            'assigned_node_id' => $nodeA,
        ]);
        $rootA = $this->tmpRoot . DIRECTORY_SEPARATOR . 'slotA';
        $rootB = $this->tmpRoot . DIRECTORY_SEPARATOR . 'slotB';
        mkdir($rootA . DIRECTORY_SEPARATOR . $other, 0775, true);
        file_put_contents($rootA . DIRECTORY_SEPARATOR . $other . DIRECTORY_SEPARATOR . 'Cookies', 'x');

        $transport = new LocalFsTransport([
            $nodeA => $rootA,
            $nodeB => $rootB,
        ], $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging');

        $job = new ProfileMigrationJob(
            $accounts,
            $nodes,
            $migrations,
            $factory->accountLocks(),
            $factory->nodeRegistry(),
            $transport,
            $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging',
            1,
        );

        $out = $job->run();
        self::assertSame(0, $out['migrated']);
        self::assertSame(1, $out['running_slots']);
        self::assertSame($nodeA, (int) $accounts->findById($other)['assigned_node_id']);
    }

    private function factory(): ServiceFactory
    {
        $factory = new ServiceFactory($this->db, [
            'internal_token' => 'test-internal',
            'app_key' => 'test-key',
            'root' => dirname(__DIR__, 2),
            'worker_url' => 'http://127.0.0.1:3000',
            'redis_memory' => true,
            'governor' => [
                'max_chrome' => 40,
                'account_lock_ttl' => 600,
            ],
            'profile_migration' => [
                'max_concurrent' => 2,
                'staging_dir' => $this->tmpRoot . DIRECTORY_SEPARATOR . 'staging',
            ],
        ]);
        $factory->useMemoryRedis(new MemoryRedis());

        return $factory;
    }

    private function seedAccountOnNode(
        AccountRepository $accounts,
        int $nodeId,
        string $root,
        string $login,
        string $trust,
    ): int {
        $id = $accounts->create([
            'login' => $login,
            'password_enc' => 'x',
            'status' => 'error',
            'trust_score' => $trust,
            'assigned_node_id' => $nodeId,
        ]);
        $dir = $root . DIRECTORY_SEPARATOR . $id;
        mkdir($dir, 0775, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'Cookies', "c-{$id}");
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'SingletonLock', 'L');

        return $id;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
