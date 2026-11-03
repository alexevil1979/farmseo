<?php

declare(strict_types=1);

namespace Farm\Services;

use RuntimeException;

/**
 * Strategy for resolving exit-IP / geo metadata for a proxy row.
 *
 * GEO_PROVIDER=stub → StubGeoProvider (e2e/geo-fixtures.json, no outbound HTTP)
 * GEO_PROVIDER=http|ip-api|ipinfo| (default) → HttpGeoProvider
 */
interface GeoProvider
{
    /**
     * @param array<string, mixed> $proxyRow proxies table row (+ optional decrypted password)
     * @return array{
     *     ip: string,
     *     country: string|null,
     *     city: string|null,
     *     region: string|null,
     *     asn: string|null,
     *     org: string|null,
     *     timezone: string|null,
     *     type: string,
     *     provider: string
     * }
     */
    public function lookup(array $proxyRow): array;
}

/**
 * Live geo via ip-api / ipinfo through the proxy under test.
 *
 * @phpstan-type HttpGet callable(array<string, mixed>, string): string
 */
final class HttpGeoProvider implements GeoProvider
{
    /**
     * @param callable(array<string, mixed>, string): string $httpGetViaProxy
     */
    public function __construct(
        private readonly mixed $httpGetViaProxy,
    ) {
        if (!is_callable($this->httpGetViaProxy)) {
            throw new RuntimeException('HttpGeoProvider requires httpGetViaProxy callable');
        }
    }

    public function lookup(array $proxyRow): array
    {
        $errors = [];

        try {
            return $this->probeIpApi($proxyRow);
        } catch (\Throwable $e) {
            $errors[] = 'ip-api: ' . $e->getMessage();
        }

        try {
            return $this->probeIpInfo($proxyRow);
        } catch (\Throwable $e) {
            $errors[] = 'ipinfo: ' . $e->getMessage();
        }

        throw new RuntimeException(implode('; ', $errors));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ip: string, country: string|null, city: string|null, region: string|null, asn: string|null, org: string|null, timezone: string|null, type: string, provider: string}
     */
    private function probeIpApi(array $row): array
    {
        $url = 'http://ip-api.com/json/?fields=status,message,country,countryCode,regionName,city,timezone,isp,org,as,query,mobile,proxy,hosting';
        $body = ($this->httpGetViaProxy)($row, $url);
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            $msg = is_array($data) ? (string) ($data['message'] ?? 'failed') : 'invalid json';
            throw new RuntimeException($msg);
        }

        return [
            'ip' => (string) ($data['query'] ?? ''),
            'country' => isset($data['countryCode']) ? (string) $data['countryCode'] : null,
            'city' => isset($data['city']) ? (string) $data['city'] : null,
            'region' => isset($data['regionName']) ? (string) $data['regionName'] : null,
            'asn' => self::normalizeAsn($data['as'] ?? null),
            'org' => isset($data['org']) ? (string) $data['org'] : (isset($data['isp']) ? (string) $data['isp'] : null),
            'timezone' => isset($data['timezone']) ? (string) $data['timezone'] : null,
            'type' => self::classifyType(
                !empty($data['mobile']),
                !empty($data['hosting']) || !empty($data['proxy']),
                (string) ($data['org'] ?? $data['isp'] ?? '')
            ),
            'provider' => 'ip-api',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ip: string, country: string|null, city: string|null, region: string|null, asn: string|null, org: string|null, timezone: string|null, type: string, provider: string}
     */
    private function probeIpInfo(array $row): array
    {
        $token = $_ENV['IPINFO_TOKEN'] ?? $_SERVER['IPINFO_TOKEN'] ?? getenv('IPINFO_TOKEN') ?: '';
        $url = 'https://ipinfo.io/json';
        if (is_string($token) && $token !== '') {
            $url .= '?token=' . rawurlencode($token);
        }

        $body = ($this->httpGetViaProxy)($row, $url);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ip'])) {
            throw new RuntimeException('ipinfo failed');
        }

        $org = isset($data['org']) ? (string) $data['org'] : '';

        return [
            'ip' => (string) $data['ip'],
            'country' => isset($data['country']) ? (string) $data['country'] : null,
            'city' => isset($data['city']) ? (string) $data['city'] : null,
            'region' => isset($data['region']) ? (string) $data['region'] : null,
            'asn' => self::normalizeAsn($org),
            'org' => $org !== '' ? $org : null,
            'timezone' => isset($data['timezone']) ? (string) $data['timezone'] : null,
            'type' => self::classifyType(false, self::looksLikeDc($org), $org),
            'provider' => 'ipinfo',
        ];
    }

    public static function classifyType(bool $mobile, bool $hosting, string $orgHint): string
    {
        if ($mobile) {
            return 'mob';
        }
        if ($hosting || self::looksLikeDc($orgHint)) {
            return 'dc';
        }

        return 'res';
    }

    public static function looksLikeDc(string $org): bool
    {
        $hay = strtolower($org);
        foreach (['digitalocean', 'hetzner', 'ovh', 'amazon', 'aws', 'google cloud', 'microsoft', 'azure', 'linode', 'vultr', 'contabo', 'choopa', 'leaseweb', 'hosting', 'datacenter', 'data center', 'server'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeAsn(mixed $as): ?string
    {
        if ($as === null || $as === '') {
            return null;
        }
        $s = trim((string) $as);
        if (preg_match('/AS(\d+)/i', $s, $m)) {
            return 'AS' . $m[1];
        }
        if (ctype_digit($s)) {
            return 'AS' . $s;
        }

        return $s !== '' ? $s : null;
    }
}

/**
 * Offline geo for E2E / CI — reads e2e/geo-fixtures.json, never opens sockets.
 */
final class StubGeoProvider implements GeoProvider
{
    /** @var array<string, mixed>|null */
    private ?array $fixtures = null;

    public function __construct(
        private readonly ?string $fixturesPath = null,
    ) {
    }

    public function lookup(array $proxyRow): array
    {
        $data = $this->load();
        $host = (string) ($proxyRow['host'] ?? '');
        $port = (int) ($proxyRow['port'] ?? 0);
        $key = $host . ':' . $port;
        $country = isset($proxyRow['country']) && $proxyRow['country'] !== ''
            ? strtoupper(substr((string) $proxyRow['country'], 0, 2))
            : null;

        $hit = null;
        $byHost = is_array($data['by_host'] ?? null) ? $data['by_host'] : [];
        if ($key !== ':' && isset($byHost[$key]) && is_array($byHost[$key])) {
            $hit = $byHost[$key];
        }

        if ($hit === null && $country !== null) {
            $byCountry = is_array($data['by_country'] ?? null) ? $data['by_country'] : [];
            if (isset($byCountry[$country]) && is_array($byCountry[$country])) {
                $hit = $byCountry[$country];
            }
        }

        if ($hit === null) {
            $hit = is_array($data['default'] ?? null) ? $data['default'] : [];
        }

        $asn = array_key_exists('asn', $hit) ? HttpGeoProvider::normalizeAsn($hit['asn']) : null;

        return [
            'ip' => (string) ($hit['ip'] ?? '127.0.0.1'),
            'country' => isset($hit['country']) ? strtoupper(substr((string) $hit['country'], 0, 2)) : ($country ?? 'RU'),
            'city' => isset($hit['city']) ? (string) $hit['city'] : null,
            'region' => isset($hit['region']) ? (string) $hit['region'] : null,
            'asn' => $asn,
            'org' => isset($hit['org']) ? (string) $hit['org'] : 'GEO_PROVIDER=stub',
            'timezone' => isset($hit['timezone']) ? (string) $hit['timezone'] : 'Europe/Moscow',
            'type' => (string) ($hit['type'] ?? 'res'),
            'provider' => 'stub',
        ];
    }

    public static function resolveFixturesPath(?string $override = null): string
    {
        if (is_string($override) && $override !== '' && is_readable($override)) {
            return $override;
        }
        $env = $_ENV['GEO_FIXTURES'] ?? $_SERVER['GEO_FIXTURES'] ?? getenv('GEO_FIXTURES') ?: '';
        if (is_string($env) && $env !== '' && is_readable($env)) {
            return $env;
        }
        $candidates = [
            '/opt/farm/e2e/geo-fixtures.json',
            dirname(__DIR__, 2) . '/e2e/geo-fixtures.json',
            dirname(__DIR__, 2) . '/geo-fixtures.json',
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        throw new RuntimeException('StubGeoProvider: geo-fixtures.json not found (set GEO_FIXTURES)');
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->fixtures !== null) {
            return $this->fixtures;
        }
        $path = self::resolveFixturesPath($this->fixturesPath);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("StubGeoProvider: cannot read {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("StubGeoProvider: invalid JSON in {$path}");
        }
        $this->fixtures = $decoded;

        return $this->fixtures;
    }
}

/**
 * Builds GeoProvider from GEO_PROVIDER env.
 */
final class GeoProviderFactory
{
    /**
     * @param callable(array<string, mixed>, string): string|null $httpGetViaProxy required for http mode
     * @param string|null $fixturesPath override for stub
     */
    public static function fromEnv(mixed $httpGetViaProxy = null, ?string $fixturesPath = null): GeoProvider
    {
        $name = strtolower((string) (
            $_ENV['GEO_PROVIDER'] ?? $_SERVER['GEO_PROVIDER'] ?? getenv('GEO_PROVIDER') ?: 'http'
        ));

        if ($name === 'stub') {
            return new StubGeoProvider($fixturesPath);
        }

        if (!is_callable($httpGetViaProxy)) {
            throw new RuntimeException('HttpGeoProvider requires httpGetViaProxy callable');
        }

        return new HttpGeoProvider($httpGetViaProxy);
    }

    public static function isStub(): bool
    {
        $name = strtolower((string) (
            $_ENV['GEO_PROVIDER'] ?? $_SERVER['GEO_PROVIDER'] ?? getenv('GEO_PROVIDER') ?: ''
        ));

        return $name === 'stub';
    }
}
