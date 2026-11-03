<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\ProxyRepository;
use Farm\Support\Crypto;
use RuntimeException;

/**
 * Live proxy health probe via GeoProvider (http ip-api/ipinfo or stub fixtures).
 */
final class ProxyChecker
{
    public const HEALTH_OK = 'ok';
    public const HEALTH_SLOW = 'slow';
    public const HEALTH_DEAD = 'dead';
    public const HEALTH_UNKNOWN = 'unknown';

    /** Latency threshold (ms) above which health = slow */
    private int $slowThresholdMs;

    /** Request timeout (seconds) */
    private int $timeoutSec;

    private ?GeoProvider $geoProvider;

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $probeOverride test double
     */
    public function __construct(
        private readonly ProxyRepository $proxies,
        private readonly Crypto $crypto,
        ?int $slowThresholdMs = null,
        ?int $timeoutSec = null,
        private readonly mixed $probeOverride = null,
        ?GeoProvider $geoProvider = null,
    ) {
        $this->slowThresholdMs = $slowThresholdMs
            ?? (int) ($_ENV['PROXY_SLOW_MS'] ?? $_SERVER['PROXY_SLOW_MS'] ?? getenv('PROXY_SLOW_MS') ?: 2000);
        $this->timeoutSec = $timeoutSec
            ?? (int) ($_ENV['PROXY_CHECK_TIMEOUT'] ?? $_SERVER['PROXY_CHECK_TIMEOUT'] ?? getenv('PROXY_CHECK_TIMEOUT') ?: 12);
        $this->geoProvider = $geoProvider;
    }

    /**
     * Build proxy endpoint URL. Empty user/pass → scheme://host:port (no userinfo).
     *
     * @param array<string, mixed> $row
     */
    public static function buildProxyUrl(array $row, ?string $password = null): string
    {
        $protocol = strtolower((string) ($row['protocol'] ?? 'http'));
        if (!in_array($protocol, ['http', 'https', 'socks4', 'socks5', 'socks5h'], true)) {
            $protocol = 'http';
        }
        // Chrome/curl HTTP forward proxy URLs use http:// even when target is HTTPS
        if ($protocol === 'https') {
            $protocol = 'http';
        }

        $host = (string) ($row['host'] ?? '');
        $port = (int) ($row['port'] ?? 0);
        $username = isset($row['username']) ? trim((string) $row['username']) : '';
        if ($username === '' && isset($row['user'])) {
            $username = trim((string) $row['user']);
        }

        $pass = $password;
        if ($pass === null && array_key_exists('password', $row) && is_string($row['password'])) {
            $pass = $row['password'];
        }
        $pass = $pass ?? '';

        // Empty credentials → http://host:port (never http://:@host:port or http://:@…)
        if ($username === '') {
            return sprintf('%s://%s:%d', $protocol, $host, $port);
        }

        return sprintf(
            '%s://%s:%s@%s:%d',
            $protocol,
            rawurlencode($username),
            rawurlencode($pass),
            $host,
            $port
        );
    }

    /**
     * Check one proxy by id, persist health + meta, return result.
     *
     * @return array{
     *     proxy_id: int,
     *     health: string,
     *     latency_ms: int|null,
     *     ip: string|null,
     *     country: string|null,
     *     city: string|null,
     *     asn: string|null,
     *     timezone: string|null,
     *     type: string|null,
     *     error: string|null
     * }
     */
    public function check(int $proxyId): array
    {
        $row = $this->proxies->findById($proxyId);
        if ($row === null) {
            throw new RuntimeException("Proxy #{$proxyId} not found");
        }

        return $this->checkRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     proxy_id: int,
     *     health: string,
     *     latency_ms: int|null,
     *     ip: string|null,
     *     country: string|null,
     *     city: string|null,
     *     asn: string|null,
     *     timezone: string|null,
     *     type: string|null,
     *     error: string|null
     * }
     */
    public function checkRow(array $row): array
    {
        $proxyId = (int) $row['id'];
        $started = microtime(true);
        $error = null;
        $info = null;

        try {
            $info = $this->probe($row);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($info === null) {
            $health = self::HEALTH_DEAD;
            $latencyMs = null;
        } elseif ($latencyMs >= $this->slowThresholdMs) {
            $health = self::HEALTH_SLOW;
        } else {
            $health = self::HEALTH_OK;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $meta = $this->decodeMeta($row['meta'] ?? null);
        if ($info !== null) {
            $meta = array_merge($meta, [
                'exit_ip' => $info['ip'],
                'city' => $info['city'],
                'region' => $info['region'],
                'asn' => $info['asn'],
                'org' => $info['org'],
                'timezone' => $info['timezone'],
                'type' => $info['type'],
                'provider' => $info['provider'],
                'last_check_error' => null,
            ]);
        } else {
            $meta['last_check_error'] = $error;
        }

        $update = [
            'health' => $health,
            'latency_ms' => $latencyMs,
            'last_check_at' => $now,
            'meta' => $meta,
        ];
        if ($info !== null && !empty($info['country'])) {
            $update['country'] = strtoupper(substr($info['country'], 0, 2));
        }

        $this->proxies->update($proxyId, $update);

        return [
            'proxy_id' => $proxyId,
            'health' => $health,
            'latency_ms' => $latencyMs,
            'ip' => $info['ip'] ?? null,
            'country' => $info['country'] ?? ($update['country'] ?? $row['country'] ?? null),
            'city' => $info['city'] ?? null,
            'asn' => $info['asn'] ?? null,
            'timezone' => $info['timezone'] ?? null,
            'type' => $info['type'] ?? null,
            'error' => $error,
        ];
    }

    /**
     * @param list<int>|null $ids null = all active
     * @return list<array<string, mixed>>
     */
    public function checkMany(?array $ids = null): array
    {
        if ($ids === null) {
            $rows = $this->proxies->findActive();
        } else {
            $rows = [];
            foreach ($ids as $id) {
                $row = $this->proxies->findById((int) $id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->checkRow($row);
        }

        return $results;
    }

    public function geoProvider(): GeoProvider
    {
        return $this->resolveGeoProvider();
    }

    /**
     * @param array<string, mixed> $row
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
    private function probe(array $row): array
    {
        if (is_callable($this->probeOverride)) {
            /** @var array<string, mixed> $out */
            $out = ($this->probeOverride)($row);

            return $out;
        }

        return $this->resolveGeoProvider()->lookup($row);
    }

    private function resolveGeoProvider(): GeoProvider
    {
        if ($this->geoProvider !== null) {
            return $this->geoProvider;
        }

        $this->geoProvider = GeoProviderFactory::fromEnv(
            fn (array $row, string $url): string => $this->httpGetViaProxy($row, $url)
        );

        return $this->geoProvider;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function httpGetViaProxy(array $row, string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl required for proxy checks');
        }

        $protocol = strtolower((string) ($row['protocol'] ?? 'http'));
        $username = isset($row['username']) ? trim((string) $row['username']) : '';
        $password = $this->crypto->decryptOrPlain(
            isset($row['password_enc']) ? (string) $row['password_enc'] : null
        );

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        // Never glue empty user/pass into the proxy URL.
        $proxyUrl = self::buildProxyUrl($row, $password);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSec,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_USERAGENT => 'FarmProxyChecker/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROXY => $proxyUrl,
        ];

        if ($protocol === 'socks5' || $protocol === 'socks5h') {
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif ($protocol === 'socks4') {
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
        } else {
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }

        // Auth only when username is non-empty; never PROXYUSERPWD=":" for blank creds.
        if ($username !== '') {
            $opts[CURLOPT_PROXYUSERPWD] = $username . ':' . ($password ?? '');
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException($err !== '' ? $err : "curl errno {$errno}");
        }
        if ($status >= 400) {
            throw new RuntimeException("HTTP {$status}");
        }

        return (string) $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
