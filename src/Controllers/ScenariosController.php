<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Services\Admission;

final class ScenariosController extends BaseController
{
    private const TYPES = ['warmup', 'pf', 'service', 'register'];

    protected function repository(): AbstractRepository
    {
        return $this->factory->scenarioRepository();
    }

    protected function resourceName(): string
    {
        return 'scenarios';
    }

    protected function writable(): array
    {
        return ['name', 'type', 'description', 'version', 'is_active', 'defaults'];
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (empty($data['name']) || empty($data['type'])) {
            throw new HttpException('validation_error', 'name and type are required', 422);
        }
        if (!in_array((string) $data['type'], self::TYPES, true)) {
            throw new HttpException('validation_error', 'Invalid scenario type', 422);
        }
        $data['version'] = (int) ($data['version'] ?? 1);
        $data['is_active'] = (int) ($data['is_active'] ?? 1);

        return $data;
    }

    public function run(Request $request, string $id): Response
    {
        $scenarioId = (int) $id;
        $scenario = $this->factory->scenarioRepository()->findById($scenarioId);
        if ($scenario === null) {
            throw new HttpException('not_found', 'Scenario not found', 404);
        }
        if (!(int) ($scenario['is_active'] ?? 0)) {
            throw new HttpException('validation_error', 'Scenario is inactive', 422);
        }

        $body = $request->jsonObject();
        $accountId = isset($body['account_id']) ? (int) $body['account_id'] : null;
        if ($accountId === null || $accountId <= 0) {
            throw new HttpException('validation_error', 'account_id is required', 422);
        }
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        $typeMap = [
            'warmup' => 'warmup',
            'pf' => 'pf',
            'service' => 'service',
            'register' => 'register',
        ];
        $taskType = $typeMap[(string) $scenario['type']] ?? 'service';

        $payload = [
            'type' => $taskType,
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'target_url' => $body['target_url'] ?? null,
            'keyword' => $body['keyword'] ?? null,
            'geo' => $body['geo'] ?? null,
            'device' => $body['device'] ?? 'desktop',
            'limits' => is_array($body['limits'] ?? null) ? $body['limits'] : [],
        ];

        $taskId = $this->factory->taskRepository()->create([
            'type' => $taskType,
            'priority' => (int) ($body['priority'] ?? 0),
            'status' => 'pending',
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'campaign_id' => isset($body['campaign_id']) ? (int) $body['campaign_id'] : null,
            'keyword_id' => isset($body['keyword_id']) ? (int) $body['keyword_id'] : null,
            'target_id' => isset($body['target_id']) ? (int) $body['target_id'] : null,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => (int) ($body['max_attempts'] ?? 3),
            'timeout' => (int) ($body['timeout'] ?? 300),
            'webhook_url' => $body['webhook_url'] ?? null,
            'scheduled_at' => $body['scheduled_at'] ?? null,
        ]);

        $fresh = $this->factory->taskRepository()->findById($taskId);
        $result = $fresh !== null
            ? $this->factory->taskQueueBuilder()->enqueue($fresh)
            : ['decision' => Admission::DEFER, 'status' => 'pending', 'defer_reason' => Admission::REASON_CHROME_CAP];

        $accepted = $result['decision'] === Admission::ALLOW;
        $this->audit($request, 'run', $scenarioId, [
            'task_id' => $taskId,
            'enqueued' => $accepted,
            'defer_reason' => $result['defer_reason'] ?? null,
        ]);

        $data = [
            'task_id' => $taskId,
            'scenario_id' => $scenarioId,
            'status' => $accepted ? 'queued' : 'pending',
        ];
        if (!$accepted && !empty($result['defer_reason'])) {
            $data['defer_reason'] = $result['defer_reason'];
        }

        return Response::json(['data' => $data], 201);
    }
}
