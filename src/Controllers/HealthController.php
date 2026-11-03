<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Jobs\MetricsJob;
use Farm\Services\ServiceFactory;
use Throwable;

final class HealthController
{
    public function __construct(private readonly ServiceFactory $factory)
    {
    }

    public function publicHealth(Request $request): Response
    {
        $dbOk = false;
        $redisOk = false;
        try {
            $this->factory->db()->fetchOne('SELECT 1 AS `ok`');
            $dbOk = true;
        } catch (Throwable) {
        }
        try {
            $this->factory->redis()->command('PING');
            $redisOk = true;
        } catch (Throwable) {
        }

        $ok = $dbOk;
        $workers = 0;
        try {
            $keys = $this->factory->redis()->keys('farm:worker:*:hb');
            $workers = count($keys);
        } catch (Throwable) {
        }

        return Response::json([
            'ok' => $ok,
            'db' => $dbOk,
            'redis' => $redisOk,
            'workers' => $workers,
        ], $ok ? 200 : 503);
    }

    public function stats(Request $request): Response
    {
        $tasks = $this->factory->taskRepository()->countByStatus();
        $accounts = [];
        try {
            $rows = $this->factory->db()->fetchAll(
                'SELECT `status`, COUNT(*) AS `c` FROM `accounts` GROUP BY `status`'
            );
            foreach ($rows as $row) {
                $accounts[(string) $row['status']] = (int) $row['c'];
            }
        } catch (Throwable) {
        }

        $proxies = [];
        try {
            $rows = $this->factory->db()->fetchAll(
                'SELECT `health`, COUNT(*) AS `c` FROM `proxies` GROUP BY `health`'
            );
            foreach ($rows as $row) {
                $proxies[(string) $row['health']] = (int) $row['c'];
            }
        } catch (Throwable) {
        }

        $queue = 0;
        $chrome = 0;
        $workers = 0;
        $chromeSessions = [];
        $maxChrome = (int) $this->factory->configValue('governor.max_chrome', 40);
        try {
            $queue = $this->factory->redis()->llen('farm:q:tasks');
            $chrome = count($this->factory->redis()->keys('farm:lock:acc:*'));
            $workers = count($this->factory->redis()->keys('farm:worker:*:hb'));
            $rawSessions = $this->factory->redis()->get('farm:cfg:chrome_sessions');
            if (is_string($rawSessions) && $rawSessions !== '') {
                $decoded = json_decode($rawSessions, true);
                if (is_array($decoded)) {
                    $chromeSessions = $decoded;
                }
            }
        } catch (Throwable) {
        }

        $metrics = [
            'cpu' => null,
            'ram' => null,
            'disk' => null,
            'chrome_count' => null,
            'captured_at' => null,
        ];
        try {
            $metrics = $this->factory->metricRepository()->latestSnapshot(
                MetricsJob::NAMES,
                MetricsJob::ENTITY_TYPE
            );
            // Prefer live chrome estimate when DB snapshot is empty/stale
            if ($metrics['chrome_count'] === null) {
                $metrics['chrome_count'] = $chrome;
            }
        } catch (Throwable) {
        }

        // Fallback: Redis mirror written by MetricsJob
        if ($metrics['cpu'] === null) {
            try {
                $raw = $this->factory->redis()->get('farm:cfg:metrics_latest');
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach (MetricsJob::NAMES as $name) {
                            if (array_key_exists($name, $decoded)) {
                                $metrics[$name] = $decoded[$name];
                            }
                        }
                        if (isset($decoded['captured_at'])) {
                            $metrics['captured_at'] = $decoded['captured_at'];
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        return Response::json([
            'data' => [
                'tasks' => $tasks,
                'accounts' => $accounts,
                'proxies' => $proxies,
                'queue_depth' => $queue,
                'chrome_active' => $chrome,
                'max_chrome' => $maxChrome,
                'workers' => $workers,
                'chrome_sessions' => $chromeSessions,
                'metrics' => $metrics,
            ],
        ]);
    }
}
