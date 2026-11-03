<?php

declare(strict_types=1);

namespace Farm\Services;

use DateTimeImmutable;
use DateTimeZone;
use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\ScenarioScheduleRepository;
use Farm\Repositories\TaskRepository;
use Farm\Support\CronExpression;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;
use Throwable;

/**
 * Brain tick: scenario_schedules → tasks → farm:q:tasks,
 * respecting account TZ night windows, Admission slots, locks, priorities.
 *
 * Deferred (pending + scheduled_at) tasks are picked up when slots free —
 * always via TaskQueueBuilder::enqueue → Admission (never raw LPUSH).
 */
final class Scheduler
{
    public const QUEUE_KEY = TaskQueueBuilder::QUEUE_KEY;

    /** Account statuses eligible for scheduled work */
    private const ELIGIBLE_STATUSES = ['ready', 'active', 'warming'];

    public function __construct(
        private readonly ScenarioScheduleRepository $schedules,
        private readonly ScenarioRepository $scenarios,
        private readonly AccountRepository $accounts,
        private readonly AccountFingerprintRepository $fingerprints,
        private readonly TaskRepository $tasks,
        private readonly RedisClient|MemoryRedis $redis,
        private readonly Governor $governor,
        private readonly Locks $locks,
        private readonly RetryPolicy $retryPolicy,
        private readonly NightWindow $nightWindow = new NightWindow(),
        private readonly int $enqueueBatch = 50,
        private readonly ?TaskQueueBuilder $queueBuilder = null,
        private readonly ?Admission $admission = null,
        private readonly ?NodeRegistry $nodeRegistry = null,
    ) {
    }

    /**
     * One scheduler tick.
     *
     * @return array{
     *     schedules_fired: int,
     *     tasks_created: int,
     *     retries_promoted: int,
     *     enqueued: int,
     *     deferred: int,
     *     skipped_night: int,
     *     skipped_locked: int,
     *     skipped_throttle: int,
     *     skipped_domain_rl: int,
     *     skipped_node: int,
     *     queue_len: int,
     *     governor: array<string, mixed>
     * }
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $stats = [
            'schedules_fired' => 0,
            'tasks_created' => 0,
            'retries_promoted' => 0,
            'enqueued' => 0,
            'deferred' => 0,
            'skipped_night' => 0,
            'skipped_locked' => 0,
            'skipped_throttle' => 0,
            'skipped_domain_rl' => 0,
            'skipped_node' => 0,
            'queue_len' => 0,
            'governor' => [],
        ];

        $created = $this->fireDueSchedules($now);
        $stats['schedules_fired'] = $created['schedules'];
        $stats['tasks_created'] = $created['tasks'];

        $stats['retries_promoted'] = $this->retryPolicy->promoteDue($now);

        $enq = $this->enqueuePending($now);
        $stats['enqueued'] = $enq['enqueued'];
        $stats['deferred'] = $enq['deferred'];
        $stats['skipped_night'] = $enq['skipped_night'];
        $stats['skipped_locked'] = $enq['skipped_locked'];
        $stats['skipped_throttle'] = $enq['skipped_throttle'];
        $stats['skipped_domain_rl'] = $enq['skipped_domain_rl'];
        $stats['skipped_node'] = $enq['skipped_node'];

        $stats['queue_len'] = $this->redis->llen(self::QUEUE_KEY);
        $stats['governor'] = $this->governor->status();

        return $stats;
    }

    /**
     * Ensure enabled schedules have next_run_at populated.
     *
     * @return int updated rows
     */
    public function bootstrapNextRuns(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now');
        $n = 0;
        foreach ($this->schedules->findEnabled() as $row) {
            if ($row['next_run_at'] !== null && $row['next_run_at'] !== '') {
                continue;
            }
            try {
                $next = $this->computeNextRun($row, $now);
                $this->schedules->update((int) $row['id'], [
                    'next_run_at' => $next->format('Y-m-d H:i:s.v'),
                ]);
                $n++;
            } catch (Throwable) {
                // skip bad cron
            }
        }

        return $n;
    }

    /**
     * @return array{schedules: int, tasks: int}
     */
    private function fireDueSchedules(DateTimeImmutable $now): array
    {
        $due = $this->schedules->findDue($now->format('Y-m-d H:i:s.v'));
        $schedules = 0;
        $tasks = 0;

        foreach ($due as $row) {
            $scenarioId = (int) $row['scenario_id'];
            $scenario = $this->scenarios->findById($scenarioId);
            if ($scenario === null || (int) ($scenario['is_active'] ?? 0) !== 1) {
                $this->advanceSchedule($row, $now);
                $schedules++;
                continue;
            }

            $override = $this->decodeJson($row['payload_override'] ?? null);
            $accountIds = $this->resolveAccountIds($override);

            foreach ($accountIds as $accountId) {
                $account = $this->accounts->findById($accountId);
                if ($account === null) {
                    continue;
                }
                if (!$this->isEligibleStatus((string) ($account['status'] ?? ''))) {
                    continue;
                }

                $tz = $this->accountTimezone($accountId);
                if ($this->nightWindow->isNight($tz, $now)) {
                    continue;
                }

                $type = $this->mapScenarioTypeToTaskType((string) ($scenario['type'] ?? 'warmup'));
                $defaults = $this->decodeJson($scenario['defaults'] ?? null);
                $payload = $this->buildPayload($type, $accountId, $scenarioId, $defaults, $override);

                $priority = (int) ($override['priority'] ?? $defaults['priority'] ?? 0);
                $timeout = (int) ($override['timeout'] ?? $defaults['timeout'] ?? 300);
                $maxAttempts = (int) ($override['max_attempts'] ?? $defaults['max_attempts'] ?? 3);

                $this->tasks->create([
                    'type' => $type,
                    'priority' => $priority,
                    'status' => 'pending',
                    'account_id' => $accountId,
                    'scenario_id' => $scenarioId,
                    'campaign_id' => $override['campaign_id'] ?? null,
                    'keyword_id' => $override['keyword_id'] ?? null,
                    'target_id' => $override['target_id'] ?? null,
                    'payload' => $payload,
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'scheduled_at' => $now->format('Y-m-d H:i:s.v'),
                    'timeout' => $timeout,
                    'webhook_url' => $override['webhook_url'] ?? null,
                ]);
                $tasks++;
            }

            $this->advanceSchedule($row, $now);
            $schedules++;
        }

        return ['schedules' => $schedules, 'tasks' => $tasks];
    }

    /**
     * @return array{
     *     enqueued: int,
     *     deferred: int,
     *     skipped_night: int,
     *     skipped_locked: int,
     *     skipped_throttle: int,
     *     skipped_domain_rl: int,
     *     skipped_node: int
     * }
     */
    private function enqueuePending(DateTimeImmutable $now): array
    {
        $out = [
            'enqueued' => 0,
            'deferred' => 0,
            'skipped_night' => 0,
            'skipped_locked' => 0,
            'skipped_throttle' => 0,
            'skipped_domain_rl' => 0,
            'skipped_node' => 0,
        ];

        $budget = $this->admission !== null
            ? $this->admission->admitBudget()
            : $this->governor->admitBudget();

        if ($budget <= 0) {
            $pending = $this->tasks->findPendingDue($now->format('Y-m-d H:i:s.v'));
            $out['skipped_throttle'] = min(count($pending), $this->enqueueBatch);

            return $out;
        }

        $limit = min($budget, $this->enqueueBatch);
        // Priority DESC via repository — deferred + fresh pending alike.
        $candidates = $this->tasks->findPendingDue($now->format('Y-m-d H:i:s.v'));

        foreach ($candidates as $task) {
            if ($out['enqueued'] >= $limit) {
                break;
            }

            $accountId = isset($task['account_id']) ? (int) $task['account_id'] : 0;
            if ($accountId <= 0) {
                continue;
            }

            $tz = $this->accountTimezone($accountId);
            if ($this->nightWindow->isNight($tz, $now)) {
                $out['skipped_night']++;
                continue;
            }

            if ($this->locks->isAccountLocked($accountId)) {
                $out['skipped_locked']++;
                continue;
            }

            if (!$this->ensureNodeForEnqueue($accountId)) {
                $out['skipped_node']++;
                continue;
            }

            $payload = $this->decodeJson($task['payload'] ?? null);
            $domain = $this->payloadDomain($payload);
            if ($domain !== null && !$this->locks->allowDomain($domain)) {
                $out['skipped_domain_rl']++;
                continue;
            }

            if ($this->queueBuilder === null) {
                $out['skipped_throttle']++;
                break;
            }

            $result = $this->queueBuilder->enqueue($task, $now);
            if ($result['decision'] === Admission::ALLOW) {
                $out['enqueued']++;
            } else {
                $out['deferred']++;
                // Chrome/CPU defer: stop this tick; account/proxy may allow other tasks.
                $reason = $result['defer_reason'] ?? '';
                if (in_array($reason, [
                    Admission::REASON_CHROME_CAP,
                    Admission::REASON_CPU,
                    Admission::REASON_RAM,
                ], true)) {
                    $out['skipped_throttle']++;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function advanceSchedule(array $schedule, DateTimeImmutable $now): void
    {
        try {
            $next = $this->computeNextRun($schedule, $now);
            $nextStr = $next->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            $nextStr = null;
        }

        $this->schedules->markRan(
            (int) $schedule['id'],
            $now->format('Y-m-d H:i:s.v'),
            $nextStr
        );
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function computeNextRun(array $schedule, DateTimeImmutable $from): DateTimeImmutable
    {
        $cron = CronExpression::parse((string) $schedule['cron_expr']);
        $tzName = (string) ($schedule['timezone'] ?? 'UTC');
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        return $cron->nextRun($from, $tz);
    }

    /**
     * @param array<string, mixed>|null $override
     * @return list<int>
     */
    private function resolveAccountIds(?array $override): array
    {
        if (is_array($override) && isset($override['account_ids']) && is_array($override['account_ids'])) {
            return array_values(array_unique(array_map('intval', $override['account_ids'])));
        }

        if (is_array($override) && isset($override['account_id'])) {
            return [(int) $override['account_id']];
        }

        if (is_array($override) && isset($override['group_id'])) {
            $rows = $this->accounts->findByGroupId((int) $override['group_id']);

            return array_map(static fn (array $r): int => (int) $r['id'], $rows);
        }

        $ids = [];
        foreach (self::ELIGIBLE_STATUSES as $status) {
            foreach ($this->accounts->findByStatus($status) as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function isEligibleStatus(string $status): bool
    {
        return in_array($status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * Sticky node gate: pin on first launch; never enqueue to a foreign/dead node.
     * Dead HB → account error + alert (no remigration).
     */
    private function ensureNodeForEnqueue(int $accountId): bool
    {
        if ($this->nodeRegistry === null) {
            return true;
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return false;
        }

        $nodeId = isset($account['assigned_node_id']) ? (int) $account['assigned_node_id'] : 0;
        if ($nodeId > 0) {
            if (!$this->nodeRegistry->isAlive($nodeId)) {
                $this->nodeRegistry->markAccountNodeDead($accountId, $nodeId);

                return false;
            }

            return true;
        }

        try {
            $this->nodeRegistry->assignAccount($accountId);

            return true;
        } catch (Throwable $e) {
            // Empty registry — allow enqueue (single-node / WORKER_URL fallback).
            if ($e instanceof \RuntimeException && $e->getMessage() === 'No nodes registered') {
                return true;
            }

            return false;
        }
    }

    private function accountTimezone(int $accountId): string
    {
        $fp = $this->fingerprints->findByAccountId($accountId);
        if ($fp !== null && !empty($fp['timezone'])) {
            return (string) $fp['timezone'];
        }

        return 'UTC';
    }

    private function mapScenarioTypeToTaskType(string $scenarioType): string
    {
        return match ($scenarioType) {
            'register' => 'register',
            'warmup' => 'warmup',
            'pf' => 'pf',
            'service' => 'service',
            default => 'warmup',
        };
    }

    /**
     * @param array<string, mixed>|null $defaults
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $type,
        int $accountId,
        int $scenarioId,
        ?array $defaults,
        ?array $override,
    ): array {
        $limits = [];
        if (isset($defaults['limits']) && is_array($defaults['limits'])) {
            $limits = $defaults['limits'];
        }
        if (isset($override['limits']) && is_array($override['limits'])) {
            $limits = array_merge($limits, $override['limits']);
        }

        return [
            'type' => $type,
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'target_url' => $override['target_url'] ?? $defaults['target_url'] ?? null,
            'keyword' => $override['keyword'] ?? $defaults['keyword'] ?? null,
            'geo' => $override['geo'] ?? $defaults['geo'] ?? null,
            'device' => $override['device'] ?? $defaults['device'] ?? 'desktop',
            'limits' => $limits,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function payloadDomain(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        $url = $payload['target_url'] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
