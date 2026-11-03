<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Services\Admission;
use Throwable;

final class TasksController extends BaseController
{
    public const MAX_BATCH = 500;

    private const TYPES = ['register', 'warmup', 'pf', 'service', 'check'];
    private const STATUSES = ['pending', 'queued', 'running', 'done', 'failed', 'retrying', 'cancelled'];

    protected function repository(): AbstractRepository
    {
        return $this->factory->taskRepository();
    }

    protected function resourceName(): string
    {
        return 'tasks';
    }

    protected function writable(): array
    {
        return [
            'type', 'priority', 'status', 'account_id', 'scenario_id', 'campaign_id',
            'keyword_id', 'target_id', 'payload', 'attempts', 'max_attempts',
            'scheduled_at', 'timeout', 'webhook_url', 'idempotency_key',
        ];
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        foreach (['status', 'type'] as $col) {
            if ($request->query($col) !== null) {
                $f[$col] = (string) $request->query($col);
            }
        }
        foreach (['account_id', 'scenario_id', 'campaign_id'] as $col) {
            if ($request->query($col) !== null) {
                $f[$col] = (int) $request->query($col);
            }
        }

        return $f;
    }

    /**
     * Create one task or a batch (up to 500). Idempotent via Idempotency-Key header
     * or per-item idempotency_key.
     */
    public function store(Request $request): Response
    {
        $body = $request->json();
        if ($body === null) {
            throw new HttpException('invalid_json', 'JSON body required', 400);
        }

        $items = array_is_list($body) ? $body : [$body];
        if (count($items) === 0) {
            throw new HttpException('validation_error', 'Empty task batch', 422);
        }
        if (count($items) > self::MAX_BATCH) {
            throw new HttpException(
                'validation_error',
                'Batch exceeds maximum of ' . self::MAX_BATCH . ' tasks',
                422,
                ['max' => self::MAX_BATCH, 'got' => count($items)]
            );
        }

        $batchKey = trim((string) ($request->header('idempotency-key') ?? ''));
        if ($batchKey !== '') {
            $cached = $this->idempotencyGet('batch:' . $batchKey);
            if ($cached !== null) {
                return Response::json($cached, 200)->withHeader('X-Idempotent-Replay', 'true');
            }
        }

        $created = [];
        $replayed = [];
        $errors = [];

        $this->factory->db()->beginTransaction();
        try {
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    $errors[] = ['index' => $i, 'error' => 'item must be an object'];
                    continue;
                }
                try {
                    $itemKey = trim((string) ($item['idempotency_key'] ?? ''));
                    if ($itemKey !== '') {
                        $cachedItem = $this->idempotencyGet('task:' . $itemKey);
                        if ($cachedItem !== null) {
                            $replayed[] = $cachedItem;
                            continue;
                        }
                    }

                    $row = $this->normalizeTaskInput($item);
                    $id = $this->factory->taskRepository()->create($row);
                    $task = $this->factory->taskRepository()->findById($id) ?? array_merge($row, ['id' => $id]);
                    $presented = $this->present($task);
                    $created[] = $presented;

                    if ($itemKey !== '') {
                        $this->idempotencyPut('task:' . $itemKey, $presented);
                    }
                } catch (HttpException $e) {
                    $errors[] = ['index' => $i, 'error' => $e->getMessage(), 'code' => $e->errorCode()];
                } catch (Throwable $e) {
                    $errors[] = ['index' => $i, 'error' => $e->getMessage()];
                }
            }

            if ($errors !== [] && $created === [] && $replayed === []) {
                $this->factory->db()->rollBack();
                throw new HttpException('validation_error', 'All tasks failed validation', 422, ['errors' => $errors]);
            }

            $this->factory->db()->commit();
        } catch (HttpException $e) {
            if ($this->factory->db()->inTransaction()) {
                $this->factory->db()->rollBack();
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->factory->db()->inTransaction()) {
                $this->factory->db()->rollBack();
            }
            throw new HttpException('server_error', $e->getMessage(), 500);
        }

        $admit = $this->admitCreated($created);

        $this->audit($request, 'create_batch', null, [
            'created' => count($created),
            'replayed' => count($replayed),
            'errors' => count($errors),
            'accepted' => $admit['accepted'],
            'deferred' => $admit['deferred'],
            'ids' => array_map(static fn (array $t): int => (int) $t['id'], $created),
        ]);

        $payload = [
            'data' => array_merge($admit['tasks'], $replayed),
            'meta' => [
                'created' => count($created),
                'replayed' => count($replayed),
                'errors' => $errors,
                'total' => count($created) + count($replayed),
                'accepted' => $admit['accepted'],
                'deferred' => $admit['deferred'],
            ],
        ];

        if ($batchKey !== '') {
            $this->idempotencyPut('batch:' . $batchKey, $payload);
        }

        $status = $created !== [] ? 201 : 200;

        return Response::json($payload, $status);
    }

    public function update(Request $request, string $id): Response
    {
        $entityId = (int) $id;
        $existing = $this->factory->taskRepository()->findById($entityId);
        if ($existing === null) {
            throw new HttpException('not_found', 'Task not found', 404);
        }
        $data = $this->filterWritable($request->jsonObject());
        unset($data['idempotency_key']);
        if (isset($data['status']) && !in_array((string) $data['status'], self::STATUSES, true)) {
            throw new HttpException('validation_error', 'Invalid task status', 422);
        }
        if (isset($data['type']) && !in_array((string) $data['type'], self::TYPES, true)) {
            throw new HttpException('validation_error', 'Invalid task type', 422);
        }
        if ($data === []) {
            throw new HttpException('validation_error', 'No updatable fields provided', 422);
        }
        $this->factory->taskRepository()->update($entityId, $data);
        $row = $this->factory->taskRepository()->findById($entityId);
        $this->audit($request, 'update', $entityId, ['input' => $this->auditSafe($data)]);

        return Response::json(['data' => $this->present($row ?? $existing)]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeTaskInput(array $item): array
    {
        $payloadIn = $item['payload'] ?? null;
        if (!is_array($payloadIn)) {
            $payloadIn = [
                'type' => $item['type'] ?? null,
                'account_id' => $item['account_id'] ?? null,
                'scenario_id' => $item['scenario_id'] ?? null,
                'target_url' => $item['target_url'] ?? null,
                'keyword' => $item['keyword'] ?? null,
                'geo' => $item['geo'] ?? null,
                'device' => $item['device'] ?? null,
                'limits' => $item['limits'] ?? [],
            ];
        }

        $type = (string) ($item['type'] ?? $payloadIn['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException('validation_error', 'type is required and must be a valid task.type', 422);
        }

        $accountId = isset($item['account_id']) ? (int) $item['account_id'] : (isset($payloadIn['account_id']) ? (int) $payloadIn['account_id'] : null);
        $scenarioId = isset($item['scenario_id']) ? (int) $item['scenario_id'] : (isset($payloadIn['scenario_id']) ? (int) $payloadIn['scenario_id'] : null);

        if ($accountId === null || $accountId <= 0) {
            throw new HttpException('validation_error', 'account_id is required', 422);
        }
        if ($scenarioId === null || $scenarioId <= 0) {
            throw new HttpException('validation_error', 'scenario_id is required', 422);
        }

        $limits = $payloadIn['limits'] ?? [];
        if (!is_array($limits)) {
            $limits = [];
        }

        $payload = [
            'type' => $type,
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'target_url' => $payloadIn['target_url'] ?? $item['target_url'] ?? null,
            'keyword' => $payloadIn['keyword'] ?? $item['keyword'] ?? null,
            'geo' => $payloadIn['geo'] ?? $item['geo'] ?? null,
            'device' => $payloadIn['device'] ?? $item['device'] ?? null,
            'limits' => $limits,
        ];
        // Pass-through keys the worker scenario loader/executor reads (not CONTRACT-required).
        foreach (['scenario_name', 'scenario', 'day'] as $extra) {
            if (array_key_exists($extra, $payloadIn)) {
                $payload[$extra] = $payloadIn[$extra];
            } elseif (array_key_exists($extra, $item)) {
                $payload[$extra] = $item[$extra];
            }
        }

        return [
            'type' => $type,
            'priority' => (int) ($item['priority'] ?? 0),
            'status' => 'pending',
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'campaign_id' => isset($item['campaign_id']) ? (int) $item['campaign_id'] : null,
            'keyword_id' => isset($item['keyword_id']) ? (int) $item['keyword_id'] : null,
            'target_id' => isset($item['target_id']) ? (int) $item['target_id'] : null,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => (int) ($item['max_attempts'] ?? 3),
            'scheduled_at' => $item['scheduled_at'] ?? null,
            'timeout' => (int) ($item['timeout'] ?? 300),
            'webhook_url' => $item['webhook_url'] ?? null,
        ];
    }

    /**
     * Admit created tasks through TaskQueueBuilder (Admission). Batch beyond
     * admit budget is deferred (pending + backoff) — never raw LPUSH.
     *
     * @param list<array<string, mixed>> $created
     * @return array{tasks: list<array<string, mixed>>, accepted: int, deferred: int}
     */
    private function admitCreated(array $created): array
    {
        $accepted = 0;
        $deferred = 0;
        $out = [];
        $budget = 0;
        try {
            $budget = $this->factory->admission()->admitBudget();
        } catch (Throwable) {
            $budget = 0;
        }
        // Chunk size N: free Chrome slots (governor / farm:cfg:max_chrome).
        $chunk = max(0, $budget);

        foreach ($created as $i => $task) {
            $taskId = (int) $task['id'];
            $fresh = $this->factory->taskRepository()->findById($taskId) ?? $task;

            if ($accepted >= $chunk) {
                $result = $this->factory->taskQueueBuilder()->defer($fresh, Admission::REASON_BATCH);
                $deferred++;
                $row = $this->present($this->factory->taskRepository()->findById($taskId) ?? $fresh);
                $row['defer_reason'] = $result['defer_reason'];
                $out[] = $row;
                continue;
            }

            try {
                $result = $this->factory->taskQueueBuilder()->enqueue($fresh);
            } catch (Throwable) {
                $result = $this->factory->taskQueueBuilder()->defer($fresh, Admission::REASON_CHROME_CAP);
            }

            $row = $this->present($this->factory->taskRepository()->findById($taskId) ?? $fresh);
            if ($result['decision'] === Admission::ALLOW) {
                $accepted++;
                $row['status'] = 'queued';
            } else {
                $deferred++;
                $row['status'] = 'pending';
                $row['defer_reason'] = $result['defer_reason'];
                if (!empty($result['scheduled_at'])) {
                    $row['scheduled_at'] = $result['scheduled_at'];
                }
            }
            $out[] = $row;
        }

        return ['tasks' => $out, 'accepted' => $accepted, 'deferred' => $deferred];
    }

    /** Task run events for panel reports. */
    public function events(Request $request, string $id): Response
    {
        $taskId = (int) $id;
        $task = $this->factory->taskRepository()->findById($taskId);
        if ($task === null) {
            throw new HttpException('not_found', 'Task not found', 404);
        }

        $runs = new \Farm\Repositories\TaskRunRepository($this->factory->db());
        $eventsRepo = new \Farm\Repositories\RunsEventRepository($this->factory->db());
        $all = [];
        foreach ($runs->findByTask($taskId) as $run) {
            foreach ($eventsRepo->findByTaskRun((int) $run['id']) as $ev) {
                $ev['task_run_id'] = (int) $run['id'];
                $ev['attempt'] = (int) ($run['attempt'] ?? 0);
                $all[] = $ev;
            }
        }
        usort($all, static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        return Response::json(['data' => $all]);
    }

    /** @return array<string, mixed>|null */
    private function idempotencyGet(string $key): ?array
    {
        $redisKey = 'farm:idem:' . hash('sha256', $key);
        try {
            $raw = $this->factory->redis()->get($redisKey);
            if ($raw === null || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return self::$idemMemory[$redisKey] ?? null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function idempotencyPut(string $key, array $payload): void
    {
        $redisKey = 'farm:idem:' . hash('sha256', $key);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $this->factory->redis()->set($redisKey, $json, 86400);
        } catch (Throwable) {
            self::$idemMemory[$redisKey] = $payload;
        }
    }

    /** @var array<string, array<string, mixed>> */
    private static array $idemMemory = [];

    protected function present(array $row): array
    {
        if (isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                $row['payload'] = $decoded;
            }
        }

        return $row;
    }
}
