<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\ProxyRepository;
use Farm\Services\ProxyChecker;
use Farm\Support\Crypto;
use Farm\Tests\TestCase;
use RuntimeException;

final class ProxyCheckerHealthTest extends TestCase
{
    public function testCheckPersistsOkSlowDead(): void
    {
        $proxies = new ProxyRepository($this->db);
        $okId = $proxies->create([
            'host' => '127.0.0.1', 'port' => 1, 'protocol' => 'http',
            'health' => 'unknown', 'is_active' => 1,
        ]);
        $slowId = $proxies->create([
            'host' => '127.0.0.2', 'port' => 1, 'protocol' => 'http',
            'health' => 'unknown', 'is_active' => 1,
        ]);
        $deadId = $proxies->create([
            'host' => '127.0.0.3', 'port' => 1, 'protocol' => 'http',
            'health' => 'unknown', 'is_active' => 1,
        ]);

        $probe = static function (array $row) use ($okId, $slowId, $deadId): array {
            $id = (int) $row['id'];
            if ($id === $deadId) {
                throw new RuntimeException('connection refused');
            }
            if ($id === $slowId) {
                usleep(60_000);

                return [
                    'ip' => '5.6.7.8', 'country' => 'DE', 'city' => 'Berlin', 'region' => 'BE',
                    'asn' => 'AS456', 'org' => 'Test', 'timezone' => 'Europe/Berlin',
                    'type' => 'res', 'provider' => 'test',
                ];
            }
            if ($id === $okId) {
                return [
                    'ip' => '1.2.3.4', 'country' => 'RU', 'city' => 'Moscow', 'region' => 'MOW',
                    'asn' => 'AS123', 'org' => 'ResISP', 'timezone' => 'Europe/Moscow',
                    'type' => 'res', 'provider' => 'test',
                ];
            }
            throw new RuntimeException('unknown proxy');
        };

        $checker = new ProxyChecker($proxies, new Crypto('test-key'), 50, 2, $probe);

        $rOk = $checker->check($okId);
        $rSlow = $checker->check($slowId);
        $rDead = $checker->check($deadId);

        self::assertSame('ok', $rOk['health']);
        self::assertSame('slow', $rSlow['health']);
        self::assertSame('dead', $rDead['health']);

        self::assertSame('ok', $proxies->findById($okId)['health']);
        self::assertSame('slow', $proxies->findById($slowId)['health']);
        self::assertSame('dead', $proxies->findById($deadId)['health']);
        self::assertNotNull($proxies->findById($okId)['last_check_at']);
        self::assertSame('RU', $proxies->findById($okId)['country']);
    }
}
