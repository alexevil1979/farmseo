<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Db\Connection;
use Farm\Repositories\AlertRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Support\RedisClient;

/**
 * Evaluate monitor rules and fan-out to alerts table + Telegram.
 *
 * Rules:
 * - worker_down: known farm:worker:{id}:hb disappeared
 * - captcha_errors: too many captcha failures in window
 * - proxy_degraded: dead share of active pool > threshold (default 20%)
 * - load_high: CPU/RAM above governor thresholds
 */
final class AlertEngine
{
    public const KNOWN_WORKERS_KEY = 'farm:cfg:monitor_workers';

    public const SOURCE = 'alert_engine';

    /** @var list<string> */
    private static array $memoryKnownWorkers = [];

    /** @var list<string>|null */
    private ?array $aliveWorkersOverride = null;

    /**
     * @param array{
     *     proxy_dead_pct?: float,
     *     captcha_window_sec?: int,
     *     captcha_threshold?: int,
     *     cpu_threshold?: float,
     *     ram_threshold?: float,
     *     disk_threshold?: float
     * } $thresholds
     */
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly Telegram $telegram,
        private readonly ProxyRepository $proxies,
        private readonly Governor $governor,
        private readonly ?RedisClient $redis = null,
        private readonly ?Connection $db = null,
        private readonly array $thresholds = [],
    ) {
    }

    /** @param list<string>|null $ids test hook */
    public function setAliveWorkersOverride(?array $ids): void
    {
        $this->aliveWorkersOverride = $ids;
    }

    /** Reset process-local known-worker memory (tests). */
    public static function resetMemoryKnownWorkers(): void
    {
        self::$memoryKnownWorkers = [];
    }

    /**
     * @param array{cpu?: float, ram?: float, disk?: float, chrome_count?: int}|null $metricsSnapshot
     * @return array{
     *     evaluated: list<string>,
     *     fired: list<array<string, mixed>>,
     *     workers_alive: list<string>,
     *     workers_down: list<string>
     * }
     */
    public function evaluate(?array $metricsSnapshot = null): array
    {
        $fired = [];
        $evaluated = [];

        $workerResult = $this->ruleWorkerDown();
        $evaluated[] = 'worker_down';
        foreach ($workerResult['alerts'] as $a) {
            $fired[] = $this->emit($a);
        }

        $captcha = $this->ruleCaptchaErrors();
        $evaluated[] = 'captcha_errors';
        if ($captcha !== null) {
            $fired[] = $this->emit($captcha);
        }

        $proxy = $this->ruleProxyDegraded();
        $evaluated[] = 'proxy_degraded';
        if ($proxy !== null) {
            $fired[] = $this->emit($proxy);
        }

        $load = $this->ruleLoadHigh($metricsSnapshot);
        $evaluated[] = 'load_high';
        if ($load !== null) {
            $fired[] = $this->emit($load);
        }

        return [
            'evaluated' => $evaluated,
            'fired' => $fired,
            'workers_alive' => $workerResult['alive'],
            'workers_down' => $workerResult['down'],
        ];
    }

    /**
     * @return array{alive: list<string>, down: list<string>, alerts: list<array<string, mixed>>}
     */
    public function ruleWorkerDown(): array
    {
        $alive = $this->listAliveWorkers();
        $previous = $this->loadKnownWorkers();
        $down = [];
        $alerts = [];

        if ($previous !== []) {
            foreach ($previous as $id) {
                if (!in_array($id, $alive, true)) {
                    $down[] = $id;
                    $alerts[] = [
                        'severity' => 'critical',
                        'source' => self::SOURCE,
                        'message' => "Worker heartbeat lost: {$id}",
                        'entity_type' => 'worker',
                        'entity_id' => ctype_digit($id) ? (int) $id : null,
                        'meta' => [
                            'rule' => 'worker_down',
                            'worker_id' => $id,
                        ],
                    ];
                }
            }
        }

        $this->storeKnownWorkers($alive);

        return ['alive' => $alive, 'down' => $down, 'alerts' => $alerts];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ruleCaptchaErrors(): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $window = max(30, (int) ($this->thresholds['captcha_window_sec'] ?? 300));
        $threshold = max(1, (int) ($this->thresholds['captcha_threshold'] ?? 5));

        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c`
                FROM `runs_events`
                WHERE `created_at` >= (NOW(3) - INTERVAL :sec SECOND)
                  AND (
                    `event` LIKE '%captcha%'
                    OR LOWER(COALESCE(`message`, '')) LIKE '%captcha%'
                  )
                  AND `level` IN ('error', 'warn')
                SQL,
            [':sec' => $window]
        );
        $fromEvents = (int) ($row['c'] ?? 0);

        $row2 = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c`
                FROM `tasks`
                WHERE `finished_at` >= (NOW(3) - INTERVAL :sec SECOND)
                  AND `status` IN ('failed', 'retrying')
                  AND LOWER(COALESCE(`error`, '')) LIKE '%captcha%'
                SQL,
            [':sec' => $window]
        );
        $fromTasks = (int) ($row2['c'] ?? 0);
        $count = max($fromEvents, $fromTasks);

        if ($count < $threshold) {
            return null;
        }

        return [
            'severity' => 'warn',
            'source' => self::SOURCE,
            'message' => "Captcha errors elevated: {$count} in last {$window}s (threshold {$threshold})",
            'entity_type' => null,
            'entity_id' => null,
            'meta' => [
                'rule' => 'captcha_errors',
                'count' => $count,
                'window_sec' => $window,
                'threshold' => $threshold,
            ],
        ];
    }

    /**
     * Dead proxies > N% of active pool.
     *
     * @return array<string, mixed>|null
     */
    public function ruleProxyDegraded(): ?array
    {
        $pctThreshold = (float) ($this->thresholds['proxy_dead_pct'] ?? 20.0);
        $active = $this->proxies->count(['is_active' => 1]);
        if ($active <= 0) {
            return null;
        }

        $dead = $this->proxies->count(['is_active' => 1, 'health' => 'dead']);
        $deadPct = ($dead / $active) * 100.0;
        if ($deadPct <= $pctThreshold) {
            return null;
        }

        return [
            'severity' => 'critical',
            'source' => self::SOURCE,
            'message' => sprintf(
                'Proxy pool degraded: %.1f%% dead (%d/%d active, threshold %.0f%%)',
                $deadPct,
                $dead,
                $active,
                $pctThreshold
            ),
            'entity_type' => 'proxy',
            'entity_id' => null,
            'meta' => [
                'rule' => 'proxy_degraded',
                'dead' => $dead,
                'active' => $active,
                'dead_pct' => round($deadPct, 2),
                'threshold_pct' => $pctThreshold,
            ],
        ];
    }

    /**
     * @param array{cpu?: float, ram?: float, disk?: float}|null $metricsSnapshot
     * @return array<string, mixed>|null
     */
    public function ruleLoadHigh(?array $metricsSnapshot = null): ?array
    {
        $load = $metricsSnapshot ?? $this->governor->sampleLoad();
        $cpu = (float) ($load['cpu'] ?? $load['cpu_pct'] ?? 0);
        $ram = (float) ($load['ram'] ?? $load['ram_pct'] ?? 0);
        $disk = isset($load['disk']) ? (float) $load['disk'] : null;

        $cpuTh = (float) ($this->thresholds['cpu_threshold'] ?? $this->governor->cpuThreshold());
        $ramTh = (float) ($this->thresholds['ram_threshold'] ?? $this->governor->ramThreshold());
        $diskTh = (float) ($this->thresholds['disk_threshold'] ?? 95.0);

        $reasons = [];
        if ($cpu > $cpuTh) {
            $reasons[] = sprintf('cpu=%.1f%%>%.0f%%', $cpu, $cpuTh);
        }
        if ($ram > $ramTh) {
            $reasons[] = sprintf('ram=%.1f%%>%.0f%%', $ram, $ramTh);
        }
        if ($disk !== null && $disk > $diskTh) {
            $reasons[] = sprintf('disk=%.1f%%>%.0f%%', $disk, $diskTh);
        }
        if ($reasons === []) {
            return null;
        }

        return [
            'severity' => 'warn',
            'source' => self::SOURCE,
            'message' => 'Host load exceeded: ' . implode(', ', $reasons),
            'entity_type' => 'system',
            'entity_id' => null,
            'meta' => [
                'rule' => 'load_high',
                'cpu' => $cpu,
                'ram' => $ram,
                'disk' => $disk,
            ],
        ];
    }

    /**
     * @param array{
     *     severity: string,
     *     source: string,
     *     message: string,
     *     entity_type?: string|null,
     *     entity_id?: int|null,
     *     meta?: array<string, mixed>
     * } $alert
     * @return array<string, mixed>
     */
    private function emit(array $alert): array
    {
        $meta = $alert['meta'] ?? [];
        $id = $this->alerts->create([
            'severity' => $alert['severity'],
            'source' => $alert['source'],
            'message' => $alert['message'],
            'entity_type' => $alert['entity_type'] ?? null,
            'entity_id' => $alert['entity_id'] ?? null,
            'is_acked' => 0,
            'meta' => $meta,
        ]);

        $tg = $this->telegram->sendAlert(
            $alert['severity'],
            $alert['source'],
            $alert['message'],
            array_merge($meta, [
                'entity_type' => $alert['entity_type'] ?? null,
                'entity_id' => $alert['entity_id'] ?? null,
            ]),
        );

        return [
            'alert_id' => $id,
            'severity' => $alert['severity'],
            'rule' => $meta['rule'] ?? null,
            'message' => $alert['message'],
            'telegram' => $tg,
        ];
    }

    /** @return list<string> */
    public function listAliveWorkers(): array
    {
        if ($this->aliveWorkersOverride !== null) {
            $ids = $this->aliveWorkersOverride;
            sort($ids);

            return array_values(array_unique(array_map('strval', $ids)));
        }

        if ($this->redis === null) {
            return [];
        }

        try {
            $keys = $this->redis->scanAll('farm:worker:*:hb');
        } catch (\Throwable) {
            try {
                $keys = $this->redis->keys('farm:worker:*:hb');
            } catch (\Throwable) {
                return [];
            }
        }

        $ids = [];
        foreach ($keys as $key) {
            if (preg_match('/^farm:worker:(.+):hb$/', $key, $m)) {
                $ids[] = $m[1];
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function loadKnownWorkers(): array
    {
        if ($this->redis === null) {
            return self::$memoryKnownWorkers;
        }
        try {
            $raw = $this->redis->get(self::KNOWN_WORKERS_KEY);
            if ($raw === null || $raw === '') {
                return self::$memoryKnownWorkers;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return self::$memoryKnownWorkers;
            }

            return array_values(array_map('strval', $decoded));
        } catch (\Throwable) {
            return self::$memoryKnownWorkers;
        }
    }

    /** @param list<string> $ids */
    private function storeKnownWorkers(array $ids): void
    {
        $ids = array_values($ids);
        self::$memoryKnownWorkers = $ids;
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->set(
                self::KNOWN_WORKERS_KEY,
                json_encode($ids, JSON_THROW_ON_ERROR),
                86400
            );
        } catch (\Throwable) {
            // memory fallback already updated
        }
    }
}
