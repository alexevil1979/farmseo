<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\ProxyRepository;
use Farm\Services\GeoProviderFactory;
use Farm\Services\HttpGeoProvider;
use Farm\Services\ProxyChecker;
use Farm\Services\StubGeoProvider;
use Farm\Support\Crypto;
use Farm\Tests\TestCase;
use RuntimeException;

final class ProxyCheckerStubGeoTest extends TestCase
{
    private string $prevGeo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevGeo = (string) (getenv('GEO_PROVIDER') ?: '');
        putenv('GEO_PROVIDER=stub');
        $_ENV['GEO_PROVIDER'] = 'stub';
        $_SERVER['GEO_PROVIDER'] = 'stub';
    }

    protected function tearDown(): void
    {
        if ($this->prevGeo === '') {
            putenv('GEO_PROVIDER');
            unset($_ENV['GEO_PROVIDER'], $_SERVER['GEO_PROVIDER']);
        } else {
            putenv('GEO_PROVIDER=' . $this->prevGeo);
            $_ENV['GEO_PROVIDER'] = $this->prevGeo;
            $_SERVER['GEO_PROVIDER'] = $this->prevGeo;
        }
        parent::tearDown();
    }

    public function testBuildProxyUrlOmitsEmptyCredentials(): void
    {
        $url = ProxyChecker::buildProxyUrl([
            'host' => 'proxy.example',
            'port' => 8888,
            'protocol' => 'http',
            'username' => null,
            'password' => null,
        ]);
        self::assertSame('http://proxy.example:8888', $url);

        $urlEmpty = ProxyChecker::buildProxyUrl([
            'host' => 'proxy.example',
            'port' => 8888,
            'protocol' => 'http',
            'username' => '',
            'password' => '',
        ], '');
        self::assertSame('http://proxy.example:8888', $urlEmpty);
        self::assertStringNotContainsString('@', $urlEmpty);
        self::assertStringNotContainsString('://:', $urlEmpty);

        $urlAuth = ProxyChecker::buildProxyUrl([
            'host' => 'proxy.example',
            'port' => 8888,
            'protocol' => 'http',
            'username' => 'u',
        ], 'p');
        self::assertSame('http://u:p@proxy.example:8888', $urlAuth);
    }

    public function testProxyWithoutLoginPasswordPassesWithFixtureGeo(): void
    {
        $fixtures = dirname(__DIR__, 2) . '/e2e/geo-fixtures.json';
        self::assertFileExists($fixtures);

        $proxies = new ProxyRepository($this->db);
        $id = $proxies->create([
            'host' => 'proxy',
            'port' => 8888,
            'protocol' => 'http',
            'username' => null,
            'password_enc' => null,
            'country' => 'RU',
            'health' => 'unknown',
            'is_active' => 1,
        ]);

        $httpCalls = 0;
        $httpGet = static function (array $row, string $url) use (&$httpCalls): string {
            $httpCalls++;
            throw new RuntimeException('external HTTP must not be called in stub mode: ' . $url);
        };

        $stub = new StubGeoProvider($fixtures);
        // Factory must pick stub even if http callable is provided
        self::assertTrue(GeoProviderFactory::isStub());
        $provider = GeoProviderFactory::fromEnv($httpGet, $fixtures);
        self::assertInstanceOf(StubGeoProvider::class, $provider);

        $checker = new ProxyChecker(
            $proxies,
            new Crypto('test-key'),
            2000,
            2,
            null,
            $stub,
        );

        $result = $checker->check($id);

        self::assertSame(0, $httpCalls, 'stub mode must not open external HTTP');
        self::assertSame('ok', $result['health']);
        self::assertNull($result['error']);
        self::assertSame('RU', $result['country']);
        self::assertSame('10.42.0.8', $result['ip']); // by_host proxy:8888
        self::assertNull($result['asn'], 'fixture asn is null');
        self::assertSame('Europe/Moscow', $result['timezone']);

        $row = $proxies->findById($id);
        self::assertNotNull($row);
        self::assertSame('ok', $row['health']);
        $meta = is_string($row['meta']) ? json_decode($row['meta'], true) : $row['meta'];
        self::assertIsArray($meta);
        self::assertSame('stub', $meta['provider']);
        self::assertNull($meta['asn']);

        // Endpoint URL for auth-less proxy stays host:port
        self::assertSame(
            'http://proxy:8888',
            ProxyChecker::buildProxyUrl($row, null)
        );
    }

    public function testHttpGeoProviderIsSelectedWhenNotStub(): void
    {
        putenv('GEO_PROVIDER=http');
        $_ENV['GEO_PROVIDER'] = 'http';
        $_SERVER['GEO_PROVIDER'] = 'http';

        $called = false;
        $httpGet = static function (array $row, string $url) use (&$called): string {
            $called = true;
            throw new RuntimeException('probe short-circuit');
        };
        $provider = GeoProviderFactory::fromEnv($httpGet);
        self::assertInstanceOf(HttpGeoProvider::class, $provider);

        try {
            $provider->lookup(['host' => 'h', 'port' => 1, 'protocol' => 'http']);
            self::fail('expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('probe short-circuit', $e->getMessage());
        }
        self::assertTrue($called);
    }
}
