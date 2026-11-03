<?php

declare(strict_types=1);

namespace Farm\Jobs;

use Farm\Repositories\BlacklistRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Services\ProxyChecker;
use Farm\Services\ProxyPool;

/**
 * Periodic proxy health sweep: update proxies.health, blacklist bad ASN/IP, rotate dead.
 */
final class ProxyHealthJob
{
    /** Known bad / hosting ASN prefixes (extend via settings later). */
    private const BAD_ASN_DEFAULT = [
        'AS14061', // DigitalOcean
        'AS24940', // Hetzner
        'AS16276', // OVH
        'AS16509', // Amazon
        'AS15169', // Google
        'AS8075',  // Microsoft
        'AS63949', // Linode
        'AS20473', // Choopa/Vultr
    ];

    /**
     * @param list<string>|null $badAsns
     */
    public function __construct(
        private readonly ProxyChecker $checker,
        private readonly ProxyRepository $proxies,
        private readonly BlacklistRepository $blacklists,
        private readonly ?ProxyPool $pool = null,
        private readonly ?array $badAsns = null,
        private readonly bool $autoRotate = true,
        private readonly bool $blacklistDatacenter = true,
    ) {
    }

    /**
     * @param list<int>|null $proxyIds null = all active
     * @return array{
     *     checked: int,
     *     by_health: array<string, int>,
     *     blacklisted: int,
     *     rotated: int,
     *     rotate_failed: int,
     *     results: list<array<string, mixed>>
     * }
     */
    public function run(?array $proxyIds = null): array
    {
        $results = $this->checker->checkMany($proxyIds);
        $byHealth = ['ok' => 0, 'slow' => 0, 'dead' => 0, 'unknown' => 0];
        $blacklisted = 0;

        foreach ($results as $result) {
            $h = (string) ($result['health'] ?? 'unknown');
            $byHealth[$h] = ($byHealth[$h] ?? 0) + 1;

            if ($this->shouldBlacklist($result)) {
                if ($this->blacklistFromResult($result)) {
                    $blacklisted++;
                }
            }
        }

        $rotated = 0;
        $rotateFailed = 0;
        if ($this->autoRotate && $this->pool !== null) {
            foreach ($this->pool->rotateDead() as $row) {
                if ($row['ok']) {
                    $rotated++;
                } else {
                    $rotateFailed++;
                }
            }
        }

        return [
            'checked' => count($results),
            'by_health' => $byHealth,
            'blacklisted' => $blacklisted,
            'rotated' => $rotated,
            'rotate_failed' => $rotateFailed,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function shouldBlacklist(array $result): bool
    {
        $asn = isset($result['asn']) ? strtoupper((string) $result['asn']) : '';
        if ($asn !== '' && in_array($asn, $this->badAsnList(), true)) {
            return true;
        }

        if ($this->blacklistDatacenter && ($result['type'] ?? '') === 'dc' && ($result['health'] ?? '') === 'dead') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function blacklistFromResult(array $result): bool
    {
        $added = false;
        $asn = isset($result['asn']) ? strtoupper((string) $result['asn']) : null;
        $ip = isset($result['ip']) ? (string) $result['ip'] : null;
        $reason = sprintf(
            'proxy health job: type=%s health=%s asn=%s',
            $result['type'] ?? '?',
            $result['health'] ?? '?',
            $asn ?? '?'
        );

        if ($asn !== null && $asn !== '') {
            if ($this->upsertBlacklist('ip', $asn, $reason)) {
                $added = true;
            }
        }
        if ($ip !== null && $ip !== '') {
            if ($this->upsertBlacklist('ip', $ip, $reason)) {
                $added = true;
            }
        }

        // Deactivate datacenter proxies on bad ASN
        if ($asn !== null && in_array($asn, $this->badAsnList(), true) && isset($result['proxy_id'])) {
            $this->proxies->update((int) $result['proxy_id'], [
                'is_active' => 0,
                'health' => 'dead',
            ]);
        }

        return $added;
    }

    private function upsertBlacklist(string $kind, string $value, string $reason): bool
    {
        $existing = $this->blacklists->findByKindValue($kind, $value);
        if ($existing !== null) {
            if ((int) ($existing['is_active'] ?? 0) === 1) {
                return false;
            }
            $this->blacklists->update((int) $existing['id'], [
                'is_active' => 1,
                'reason' => $reason,
            ]);

            return true;
        }

        $this->blacklists->create([
            'kind' => $kind,
            'value' => $value,
            'reason' => $reason,
            'is_active' => 1,
        ]);

        return true;
    }

    /** @return list<string> */
    private function badAsnList(): array
    {
        if ($this->badAsns !== null) {
            return array_map('strtoupper', $this->badAsns);
        }

        $env = $_ENV['PROXY_BAD_ASN'] ?? $_SERVER['PROXY_BAD_ASN'] ?? getenv('PROXY_BAD_ASN') ?: '';
        if (is_string($env) && $env !== '') {
            return array_map(
                static fn (string $s): string => strtoupper(trim($s)),
                explode(',', $env)
            );
        }

        return self::BAD_ASN_DEFAULT;
    }
}
