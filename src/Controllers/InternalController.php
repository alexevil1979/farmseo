<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AccountSessionRepository;
use Farm\Repositories\MetricRepository;
use Farm\Repositories\TaskRunRepository;
use Farm\Services\ServiceFactory;
use Throwable;

/**
 * Node → PHP internal endpoints (X-Internal-Token required by middleware).
 */
final class InternalController
{
    private const TASK_STATUSES = ['pending', 'queued', 'running', 'done', 'failed', 'retrying', 'cancelled'];
    private const TERMINAL = ['done', 'failed', 'cancelled'];

    public function __construct(private readonly ServiceFactory $factory)
    {
    }

    public function taskResult(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || array_is_list($body)) {
            throw new HttpException('invalid_json', 'JSON object body required', 400);
        }

        foreach (['task_id', 'status', 'error', 'metrics', 'screenshots'] as $field) {
            if (!array_key_exists($field, $body)) {
                throw new HttpException('validation_error', "Field {$field} is required", 400);
            }
        }

        $taskId = (int) $body['task_id'];
        $status = (string) $body['status'];
        if ($taskId <= 0 || !in_array($status, self::TASK_STATUSES, true)) {
            throw new HttpException('validation_error', 'Invalid task_id or status', 400);
        }
        if (!is_array($body['metrics'])) {
            throw new HttpException('validation_error', 'metrics must be an object', 400);
        }
        if (!is_array($body['screenshots'])) {
            throw new HttpException('validation_error', 'screenshots must be an array', 400);
        }

        $tasks = $this->factory->taskRepository();
        $task = $tasks->findById($taskId);
        if ($task === null) {
            return Response::json(['ok' => false, 'error' => 'task_not_found'], 404);
        }

        $error = $body['error'];
        if ($error !== null && !is_string($error)) {
            throw new HttpException('validation_error', 'error must be string or null', 400);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        // CONTRACT §12.6: worker failures go through RetryPolicy (max_attempts → retrying|failed).
        // Explicit retrying/cancelled/done from worker are honoured as-is.
        $effectiveStatus = $status;
        $retryMeta = null;
        if ($status === 'failed') {
            $retryMeta = $this->factory->retryPolicy()->applyFailure(
                $task,
                is_string($error) ? $error : 'worker failed',
            );
            $effectiveStatus = $retryMeta['status'];
            $task = $tasks->findById($taskId) ?? array_merge($task, ['status' => $effectiveStatus]);
        } else {
            $update = [
                'status' => $status,
                'error' => $error,
            ];
            if (empty($task['started_at']) && in_array($status, ['running', 'done', 'retrying'], true)) {
                $update['started_at'] = $now;
            }
            if ($status === 'running') {
                // First claim of this attempt: bump attempts when still at previous count.
                $attempts = (int) ($task['attempts'] ?? 0);
                if (isset($body['attempt'])) {
                    $update['attempts'] = max($attempts, (int) $body['attempt']);
                } elseif ($attempts === 0 || ($task['status'] ?? '') !== 'running') {
                    $update['attempts'] = max(1, $attempts);
                }
            }
            if (in_array($status, self::TERMINAL, true)) {
                $update['finished_at'] = $now;
            }
            $tasks->update($taskId, $update);
            $task = $tasks->findById($taskId) ?? array_merge($task, $update);
        }

        $runs = new TaskRunRepository($this->factory->db());
        if (isset($body['attempt'])) {
            $attempt = max(1, (int) $body['attempt']);
        } else {
            $attempt = max(1, (int) ($task['attempts'] ?? 1));
        }

        $existingRun = $runs->findByTaskAttempt($taskId, $attempt);
        $runData = [
            'status' => $effectiveStatus,
            'error' => $error,
            'metrics' => $body['metrics'],
            'screenshots' => $body['screenshots'],
            'worker_id' => $body['worker_id'] ?? null,
        ];
        if (in_array($effectiveStatus, self::TERMINAL, true)) {
            $runData['finished_at'] = $now;
        }
        if ($existingRun === null) {
            $runId = $runs->create(array_merge($runData, [
                'task_id' => $taskId,
                'attempt' => $attempt,
                'started_at' => $now,
            ]));
        } else {
            $runId = (int) $existingRun['id'];
            $runs->update($runId, $runData);
        }

        // Persist step events from worker (optional; UI shows them under task detail).
        if ($runId > 0 && isset($body['events']) && is_array($body['events']) && $body['events'] !== []) {
            $eventsRepo = new \Farm\Repositories\RunsEventRepository($this->factory->db());
            $existingCount = count($eventsRepo->findByTaskRun($runId));
            // Avoid duplicating on repeated terminal reports for the same attempt.
            if ($existingCount === 0) {
                foreach ($body['events'] as $ev) {
                    if (!is_array($ev)) {
                        continue;
                    }
                    $name = (string) ($ev['event'] ?? $ev['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $level = (string) ($ev['level'] ?? 'info');
                    $message = isset($ev['message']) ? (string) $ev['message'] : null;
                    $data = null;
                    if (isset($ev['data']) && is_array($ev['data'])) {
                        $data = $ev['data'];
                    } elseif (isset($ev['payload']) && is_array($ev['payload'])) {
                        $data = $ev['payload'];
                    }
                    try {
                        $eventsRepo->append($runId, $name, $level, $message, $data);
                    } catch (Throwable) {
                    }
                }
            }
        }

        $metricsRepo = new MetricRepository($this->factory->db());
        foreach ($body['metrics'] as $name => $value) {
            if (!is_string($name) && !is_int($name)) {
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }
            try {
                $metricsRepo->record('task', $taskId, (string) $name, $value);
            } catch (Throwable) {
            }
        }

        // Per-Chrome samples (optional keys from worker)
        foreach (['chrome_cpu_pct' => 'cpu', 'chrome_ram_mb' => 'ram_mb'] as $metricKey => $metricName) {
            if (!isset($body['metrics'][$metricKey]) || !is_numeric($body['metrics'][$metricKey])) {
                continue;
            }
            $accountId = (int) ($task['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            try {
                $metricsRepo->record(
                    'account',
                    $accountId,
                    $metricName,
                    $body['metrics'][$metricKey],
                    $metricName === 'cpu' ? 'pct' : 'mb',
                    ['task_id' => $taskId, 'source' => 'worker']
                );
            } catch (Throwable) {
            }
        }

        $fresh = $tasks->findById($taskId) ?? $task;
        if (in_array($effectiveStatus, self::TERMINAL, true)) {
            $accountId = (int) ($fresh['account_id'] ?? 0);
            if ($accountId > 0) {
                try {
                    $this->factory->accountLocks()->forceUnlockAccount($accountId);
                } catch (Throwable) {
                }
            }
            try {
                $this->factory->webhooks()->onTaskFinished($fresh);
            } catch (Throwable) {
            }
        }

        $this->factory->auditLogRepository()->log(
            null,
            'task_result',
            'tasks',
            $taskId,
            $request->ip(),
            [
                'status' => $effectiveStatus,
                'reported' => $status,
                'retry' => $retryMeta,
            ]
        );

        return Response::json(['ok' => true, 'status' => $effectiveStatus]);
    }

    public function sessionStart(Request $request): Response
    {
        $body = $request->jsonObject();
        if (!isset($body['account_id'])) {
            throw new HttpException('validation_error', 'account_id is required', 400);
        }
        $accountId = (int) $body['account_id'];
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        // CONTRACT §5 session/start runs on Node :3000. PHP path records DB state when
        // Node (or LiveView) reports ports — does not launch Chrome itself.
        $base = (int) $this->factory->configValue('vnc_base_port', 5900);
        $vncPort = isset($body['vnc_port']) ? (int) $body['vnc_port'] : ($base + ($accountId % 1000));

        $sessions = new AccountSessionRepository($this->factory->db());
        $sessions->create([
            'account_id' => $accountId,
            'worker_id' => $body['worker_id'] ?? null,
            'vnc_port' => $vncPort,
            'novnc_url' => $body['novnc_url'] ?? ('/novnc/?port=' . $vncPort),
            'status' => 'active',
            'meta' => $body['meta'] ?? ['source' => 'internal_php'],
        ]);

        return Response::json(['vnc_port' => $vncPort]);
    }

    public function sessionStop(Request $request): Response
    {
        $body = $request->jsonObject();
        if (!isset($body['account_id'])) {
            throw new HttpException('validation_error', 'account_id is required', 400);
        }
        $accountId = (int) $body['account_id'];
        // End DB rows; port release is owned by the Node display manager / LiveView::stop.
        $sessions = new AccountSessionRepository($this->factory->db());
        foreach ($sessions->findActiveByAccount($accountId) as $session) {
            $sessions->endSession((int) $session['id']);
        }

        return Response::json(['ok' => true]);
    }

    public function health(Request $request): Response
    {
        $workers = 0;
        $chrome = 0;
        $chromeSessions = [];
        try {
            $workers = count($this->factory->redis()->keys('farm:worker:*:hb'));
            $chrome = count($this->factory->redis()->keys('farm:lock:acc:*'));
            $raw = $this->factory->redis()->get('farm:cfg:chrome_sessions');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $chromeSessions = $decoded;
                }
            }
        } catch (Throwable) {
        }

        return Response::json([
            'ok' => true,
            'workers' => $workers,
            'chrome' => $chrome,
            'chrome_sessions' => $chromeSessions,
        ]);
    }
}
