<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\AccountSessionRepository;
use Farm\Support\RedisClient;
use RuntimeException;
use Throwable;

/**
 * Interactive Chrome live-view via worker Xvfb/x11vnc/websockify stack.
 *
 * - Issues stream URL proxied through the panel (/live/{accountId}/…)
 * - Tracks busy VNC/websockify ports in Redis
 * - Auto-stops idle sessions (no touch within idle TTL)
 */
final class LiveView
{
    public const REDIS_ACC = 'farm:live:acc:%d';
    public const REDIS_PORTS = 'farm:live:ports';
    public const DEFAULT_IDLE_SEC = 600;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountSessionRepository $sessions,
        private readonly ?RedisClient $redis,
        private readonly string $workerUrl,
        private readonly string $internalToken,
        private readonly int $vncBasePort = 5900,
        private readonly int $idleSec = self::DEFAULT_IDLE_SEC,
        private readonly int $workerTimeoutSec = 30,
        private readonly ?SessionRouter $sessionRouter = null,
    ) {
    }

    /**
     * Start (or refresh) live session for account.
     *
     * @return array{
     *     session_id: int,
     *     account_id: int,
     *     vnc_port: int|null,
     *     ws_port: int|null,
     *     display: int|null,
     *     novnc_url: string,
     *     status: string
     * }
     */
    public function start(int $accountId): array
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found');
        }

        $existing = $this->readState($accountId);
        if ($existing !== null && ($existing['status'] ?? '') === 'active') {
            $this->touch($accountId);
            $novnc = (string) ($existing['novnc_url'] ?? $this->streamUrl($accountId));

            return [
                'session_id' => (int) ($existing['session_id'] ?? 0),
                'account_id' => $accountId,
                'vnc_port' => isset($existing['vnc_port']) ? (int) $existing['vnc_port'] : null,
                'ws_port' => isset($existing['ws_port']) ? (int) $existing['ws_port'] : null,
                'display' => isset($existing['display']) ? (int) $existing['display'] : null,
                'novnc_url' => $novnc,
                'status' => 'active',
            ];
        }

        $result = $this->callWorker('/internal/session/start', ['account_id' => $accountId]);
        $vncPort = isset($result['vnc_port']) ? (int) $result['vnc_port'] : null;
        $wsPort = isset($result['ws_port']) ? (int) $result['ws_port'] : null;
        $display = isset($result['display']) ? (int) $result['display'] : null;
        $novncPath = isset($result['novnc_path']) ? (string) $result['novnc_path'] : null;
        $novncUrl = $novncPath !== null && $novncPath !== ''
            ? $novncPath
            : $this->streamUrl($accountId);

        $sessionId = $this->sessions->create([
            'account_id' => $accountId,
            'worker_id' => $result['worker_id'] ?? null,
            'vnc_port' => $vncPort,
            'novnc_url' => $novncUrl,
            'status' => 'active',
            'meta' => [
                'ws_port' => $wsPort,
                'display' => $display,
                'novnc_path' => $novncPath,
                'source' => 'live_view',
            ],
        ]);

        $state = [
            'session_id' => $sessionId,
            'account_id' => $accountId,
            'vnc_port' => $vncPort,
            'ws_port' => $wsPort,
            'display' => $display,
            'novnc_url' => $novncUrl,
            'status' => 'active',
            'last_seen' => time(),
            'started_at' => time(),
        ];
        $this->writeState($accountId, $state);
        $this->markPortsBusy($vncPort, $wsPort);

        return [
            'session_id' => $sessionId,
            'account_id' => $accountId,
            'vnc_port' => $vncPort,
            'ws_port' => $wsPort,
            'display' => $display,
            'novnc_url' => $novncUrl,
            'status' => 'active',
        ];
    }

    /**
     * Stop live session and free ports on the worker.
     */
    public function stop(int $accountId): bool
    {
        $state = $this->readState($accountId);
        try {
            $this->callWorker('/internal/session/stop', ['account_id' => $accountId]);
        } catch (Throwable $e) {
            // Still release local bookkeeping so ports do not leak in Redis.
            $this->clearState($accountId, $state);
            foreach ($this->sessions->findActiveByAccount($accountId) as $session) {
                $this->sessions->endSession((int) $session['id']);
            }
            throw $e;
        }

        $this->clearState($accountId, $state);
        foreach ($this->sessions->findActiveByAccount($accountId) as $session) {
            $this->sessions->endSession((int) $session['id']);
        }

        return true;
    }

    /**
     * Panel-proxied noVNC URL for an account stream.
     */
    public function streamUrl(int $accountId): string
    {
        return '/live/' . $accountId . '/vnc.html?autoconnect=1&resize=scale';
    }

    /**
     * Refresh idle timer while operator watches.
     */
    public function touch(int $accountId): void
    {
        $state = $this->readState($accountId);
        if ($state === null) {
            return;
        }
        $state['last_seen'] = time();
        $this->writeState($accountId, $state);
    }

    /**
     * @return list<int>
     */
    public function busyPorts(): array
    {
        if ($this->redis === null) {
            return $this->busyPortsFromDb();
        }
        try {
            $raw = $this->redis->command('SMEMBERS', self::REDIS_PORTS);
            if (!is_array($raw)) {
                return [];
            }
            $ports = array_map(static fn (mixed $p): int => (int) $p, $raw);
            sort($ports);

            return array_values(array_unique($ports));
        } catch (Throwable) {
            return $this->busyPortsFromDb();
        }
    }

    /**
     * Stop sessions that have been idle longer than idleSec.
     *
     * @return list<int> stopped account ids
     */
    public function stopIdle(?int $now = null): array
    {
        $now ??= time();
        $stopped = [];

        foreach ($this->listActiveAccountIds() as $accountId) {
            $state = $this->readState($accountId);
            $lastSeen = (int) ($state['last_seen'] ?? $state['started_at'] ?? 0);
            if ($lastSeen <= 0) {
                // Fall back to DB session started_at
                $active = $this->sessions->findActiveByAccount($accountId);
                if ($active === []) {
                    $this->clearState($accountId, $state);
                    continue;
                }
                $started = strtotime((string) ($active[0]['started_at'] ?? '')) ?: 0;
                $lastSeen = $started;
            }
            if ($lastSeen > 0 && ($now - $lastSeen) < $this->idleSec) {
                continue;
            }
            try {
                $this->stop($accountId);
                $stopped[] = $accountId;
            } catch (Throwable) {
                // Worker may be down; local DB/Redis cleanup in stop() still runs.
                if ($this->sessions->findActiveByAccount($accountId) === []) {
                    $stopped[] = $accountId;
                }
            }
        }

        return $stopped;
    }

    /**
     * Capture a screenshot now via worker (does not require live-view).
     *
     * @return array{account_id: int, path: string, filename: string, mtime: int}
     */
    public function screenshotNow(int $accountId): array
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found');
        }

        $result = $this->callWorker('/internal/account/screenshot', ['account_id' => $accountId]);
        $path = (string) ($result['path'] ?? '');
        $filename = (string) ($result['filename'] ?? basename($path));
        if ($path === '' || $filename === '') {
            throw new RuntimeException('Worker returned empty screenshot path');
        }

        return [
            'account_id' => $accountId,
            'path' => $path,
            'filename' => $filename,
            'mtime' => (int) ($result['mtime'] ?? time()),
        ];
    }

    /**
     * Latest screenshot on disk (no worker call).
     *
     * @return array{account_id: int, path: string, filename: string, mtime: int}|null
     */
    public function latestScreenshot(int $accountId, string $root): ?array
    {
        $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'screenshots';
        if (!is_dir($dir)) {
            return null;
        }
        $candidates = glob($dir . DIRECTORY_SEPARATOR . '*account_' . $accountId . '*') ?: [];
        $candidates = array_merge(
            $candidates,
            glob($dir . DIRECTORY_SEPARATOR . '*_' . $accountId . '_*') ?: []
        );
        // Also scan one-level task subdirs
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $sub) {
            $candidates = array_merge(
                $candidates,
                glob($sub . DIRECTORY_SEPARATOR . '*account_' . $accountId . '*') ?: []
            );
        }
        usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        if ($candidates === []) {
            return null;
        }
        $path = $candidates[0];
        $rel = 'storage/screenshots/' . ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');

        return [
            'account_id' => $accountId,
            'path' => $rel,
            'filename' => basename($path),
            'mtime' => (int) filemtime($path),
        ];
    }

    /**
     * @return list<int>
     */
    private function listActiveAccountIds(): array
    {
        $ids = [];
        if ($this->redis !== null) {
            try {
                foreach ($this->redis->keys('farm:live:acc:*') as $key) {
                    if (preg_match('/farm:live:acc:(\d+)$/', $key, $m)) {
                        $ids[] = (int) $m[1];
                    }
                }
            } catch (Throwable) {
            }
        }
        if ($ids === []) {
            // DB fallback: any starting/active session
            foreach ($this->sessions->findAllActive() as $row) {
                $ids[] = (int) $row['account_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function busyPortsFromDb(): array
    {
        $ports = [];
        foreach ($this->sessions->findAllActive() as $row) {
            if (!empty($row['vnc_port'])) {
                $ports[] = (int) $row['vnc_port'];
            }
            $meta = $row['meta'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (is_array($meta) && !empty($meta['ws_port'])) {
                $ports[] = (int) $meta['ws_port'];
            }
        }
        sort($ports);

        return array_values(array_unique($ports));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(int $accountId): ?array
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            $raw = $this->redis->get(sprintf(self::REDIS_ACC, $accountId));
            if ($raw === null || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(int $accountId, array $state): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $ttl = max($this->idleSec * 2, 120);
            $this->redis->set(
                sprintf(self::REDIS_ACC, $accountId),
                json_encode($state, JSON_THROW_ON_ERROR),
                $ttl
            );
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function clearState(int $accountId, ?array $state): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->del(sprintf(self::REDIS_ACC, $accountId));
            if ($state !== null) {
                $this->unmarkPorts(
                    isset($state['vnc_port']) ? (int) $state['vnc_port'] : null,
                    isset($state['ws_port']) ? (int) $state['ws_port'] : null,
                );
            }
        } catch (Throwable) {
        }
    }

    private function markPortsBusy(?int $vncPort, ?int $wsPort): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            if ($vncPort !== null && $vncPort > 0) {
                $this->redis->command('SADD', self::REDIS_PORTS, (string) $vncPort);
            }
            if ($wsPort !== null && $wsPort > 0) {
                $this->redis->command('SADD', self::REDIS_PORTS, (string) $wsPort);
            }
        } catch (Throwable) {
        }
    }

    private function unmarkPorts(?int $vncPort, ?int $wsPort): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            if ($vncPort !== null && $vncPort > 0) {
                $this->redis->command('SREM', self::REDIS_PORTS, (string) $vncPort);
            }
            if ($wsPort !== null && $wsPort > 0) {
                $this->redis->command('SREM', self::REDIS_PORTS, (string) $wsPort);
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function callWorker(string $path, array $body): array
    {
        $accountId = isset($body['account_id']) ? (int) $body['account_id'] : 0;
        $base = $this->workerUrl;
        if ($this->sessionRouter !== null && $accountId > 0) {
            $base = $this->sessionRouter->resolveWorkerUrl($accountId);
        }
        $url = rtrim($base, '/') . $path;
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl required to contact worker');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Token: ' . $this->internalToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->workerTimeoutSec,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            throw new RuntimeException($err !== '' ? $err : 'worker request failed');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid worker response');
        }
        if ($code >= 400 || (isset($decoded['ok']) && $decoded['ok'] === false)) {
            throw new RuntimeException((string) ($decoded['error'] ?? 'worker error ' . $code));
        }

        return $decoded;
    }
}
