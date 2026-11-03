<?php

declare(strict_types=1);

namespace Farm\Jobs;

use DateInterval;
use DateTimeImmutable;
use Farm\Repositories\TaskRepository;
use Farm\Services\Locks;
use Farm\Services\RetryPolicy;
use Farm\Support\RedisClient;

/**
 * Garbage collection:
 * - reclaim stale running tasks (retry / fail)
 * - kill orphan Chrome processes (Linux)
 * - remove SingletonLock / profile lock leftovers
 * - drop Redis locks for accounts without running work (TTL remains safety net)
 */
final class GcJob
{
    private const SINGLETON_FILES = [
        'SingletonLock',
        'SingletonCookie',
        'SingletonSocket',
        'lockfile',
        'DevToolsActivePort',
        '.farm.lock',
    ];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RetryPolicy $retryPolicy,
        private readonly Locks $locks,
        private readonly RedisClient $redis,
        private readonly string $profilesRoot,
        private readonly int $staleRunningGraceSec = 60,
        private readonly bool $killOrphanChrome = true,
    ) {
    }

    /**
     * @return array{
     *     stale_tasks: int,
     *     retried: int,
     *     failed: int,
     *     locks_cleared: array{accounts: int, proxies: int},
     *     singletons_removed: int,
     *     chrome_killed: int,
     *     dead_workers: int
     * }
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');

        $taskResult = $this->reclaimStaleTasks($now);
        $busyAccounts = $this->tasks->accountIdsWithStatus('running');
        $locksCleared = $this->locks->releaseStale($busyAccounts, []);
        $singletons = $this->cleanSingletonLocks($busyAccounts);
        $chromeKilled = $this->killOrphanChrome ? $this->killOrphanChromeProcesses() : 0;
        $deadWorkers = $this->sweepDeadWorkerKeys();
        $resultsFlushed = $this->flushResultsBuffer();

        return [
            'stale_tasks' => $taskResult['stale'],
            'retried' => $taskResult['retried'],
            'failed' => $taskResult['failed'],
            'locks_cleared' => $locksCleared,
            'singletons_removed' => $singletons,
            'chrome_killed' => $chromeKilled,
            'dead_workers' => $deadWorkers,
            'results_flushed' => $resultsFlushed,
        ];
    }

    /**
     * Drain farm:q:results leftover when PHP was briefly down during worker report.
     * Idempotent: re-applies terminal/running status via same columns as InternalController.
     */
    private function flushResultsBuffer(int $limit = 100): int
    {
        $flushed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $raw = $this->redis->rpop('farm:q:results');
            if ($raw === null || $raw === '') {
                break;
            }
            $body = json_decode($raw, true);
            if (!is_array($body) || !isset($body['task_id'], $body['status'])) {
                continue;
            }
            $taskId = (int) $body['task_id'];
            $task = $this->tasks->findById($taskId);
            if ($task === null) {
                continue;
            }
            $status = (string) $body['status'];
            // Skip if already at same terminal status (idempotent)
            if (in_array($status, ['done', 'failed', 'cancelled'], true)
                && ($task['status'] ?? '') === $status) {
                $flushed++;
                continue;
            }
            if ($status === 'failed') {
                $this->retryPolicy->applyFailure(
                    $task,
                    is_string($body['error'] ?? null) ? (string) $body['error'] : 'flushed from farm:q:results'
                );
            } elseif ($status === 'running' && ($task['status'] ?? '') === 'queued') {
                $this->tasks->update($taskId, [
                    'status' => 'running',
                    'started_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                ]);
            } elseif (in_array($status, ['done', 'cancelled'], true)) {
                $this->tasks->update($taskId, [
                    'status' => $status,
                    'error' => $body['error'] ?? null,
                    'finished_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                ]);
            }
            $flushed++;
        }

        return $flushed;
    }

    /**
     * @return array{stale: int, retried: int, failed: int}
     */
    private function reclaimStaleTasks(DateTimeImmutable $now): array
    {
        $stale = 0;
        $retried = 0;
        $failed = 0;

        foreach ($this->tasks->getAll(['status' => 'running']) as $task) {
            if (!$this->isTaskStale($task, $now)) {
                continue;
            }
            $stale++;
            $result = $this->retryPolicy->applyFailure(
                $task,
                'gc: stale running task (worker/chrome lost)',
                $now
            );
            if ($result['status'] === 'failed') {
                $failed++;
            } else {
                $retried++;
            }

            $accountId = isset($task['account_id']) ? (int) $task['account_id'] : 0;
            if ($accountId > 0) {
                $this->locks->forceUnlockAccount($accountId);
            }
        }

        return ['stale' => $stale, 'retried' => $retried, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isTaskStale(array $task, DateTimeImmutable $now): bool
    {
        $timeout = (int) ($task['timeout'] ?? 300);
        $grace = max($this->staleRunningGraceSec, $timeout + $this->staleRunningGraceSec);

        $started = $task['started_at'] ?? $task['updated_at'] ?? null;
        if (!is_string($started) || $started === '') {
            return true;
        }

        try {
            $startedAt = new DateTimeImmutable($started);
        } catch (\Exception) {
            return true;
        }

        $deadline = $startedAt->add(new DateInterval('PT' . $grace . 'S'));
        if ($now < $deadline) {
            return false;
        }

        // If worker heartbeat exists for any worker, still reclaim by age —
        // heartbeat proves worker alive, not that this task is progressing.
        return true;
    }

    /**
     * @param list<int> $busyAccountIds skip profiles actively running
     */
    private function cleanSingletonLocks(array $busyAccountIds): int
    {
        $busy = array_fill_keys($busyAccountIds, true);
        $root = $this->profilesRoot;
        if ($root === '' || !is_dir($root)) {
            return 0;
        }

        $removed = 0;
        $entries = @scandir($root);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $accountId = ctype_digit($entry) ? (int) $entry : null;
            if ($accountId !== null && isset($busy[$accountId])) {
                continue;
            }

            foreach (self::SINGLETON_FILES as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) || is_link($path)) {
                    // Only remove Singleton* / lockfile when no busy task;
                    // .farm.lock: remove if pid dead or stale > 30 min
                    if ($name === '.farm.lock' && !$this->isFarmLockStale($path)) {
                        continue;
                    }
                    if (@unlink($path)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    private function isFarmLockStale(string $path): bool
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return true;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return true;
        }
        $ts = isset($meta['ts']) ? (int) $meta['ts'] : 0;
        $ageMs = (int) (microtime(true) * 1000) - $ts;
        if ($ageMs > 30 * 60 * 1000) {
            return true;
        }
        $pid = isset($meta['pid']) ? (int) $meta['pid'] : 0;
        if ($pid <= 0) {
            return true;
        }

        return !$this->isPidAlive($pid);
    }

    private function killOrphanChromeProcesses(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0;
        }

        $killed = 0;
        $out = [];
        // Headful farm Chrome usually launched with --user-data-dir under profiles
        @exec("ps -eo pid=,args= 2>/dev/null | grep -E '[c]hrome|[c]hromium' || true", $out);

        $busyDirs = [];
        foreach ($this->tasks->accountIdsWithStatus('running') as $aid) {
            $busyDirs[$this->profilesRoot . '/' . $aid] = true;
            $busyDirs[$this->profilesRoot . DIRECTORY_SEPARATOR . $aid] = true;
        }

        foreach ($out as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                continue;
            }
            $pid = (int) $m[1];
            $args = $m[2];
            if (!str_contains($args, 'user-data-dir')) {
                continue;
            }
            // Skip if user-data-dir maps to a busy account profile
            $busy = false;
            foreach ($busyDirs as $dir => $_) {
                if ($dir !== '' && str_contains($args, $dir)) {
                    $busy = true;
                    break;
                }
            }
            if ($busy) {
                continue;
            }
            // Only kill if clearly under our profiles root
            if (!str_contains($args, $this->profilesRoot)) {
                continue;
            }
            if (@posix_kill($pid, 9)) {
                $killed++;
            } else {
                @exec('kill -9 ' . $pid . ' 2>/dev/null');
                $killed++;
            }
        }

        return $killed;
    }

    private function sweepDeadWorkerKeys(): int
    {
        // Heartbeat keys expire via TTL 30s; nothing to delete usually.
        // Count missing — for stats, scan remaining hb keys.
        $keys = $this->redis->scanAll('farm:worker:*:hb');

        return count($keys);
    }

    private function isPidAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
            $joined = implode(' ', $out);

            return str_contains($joined, (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return file_exists('/proc/' . $pid);
    }
}
