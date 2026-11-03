<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\TaskRepository;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;

/**
 * Single admission gate for farm:q:tasks.
 *
 * Never rejects permanently: returns allow | defer so callers keep the task
 * as pending with backoff (CONTRACT task.status stays pending|queued|…).
 */
final class Admission
{
    public const ALLOW = 'allow';
    public const DEFER = 'defer';

    public const REASON_CHROME_CAP = 'chrome_cap';
    public const REASON_CPU = 'cpu_throttle';
    public const REASON_RAM = 'ram_throttle';
    public const REASON_ACCOUNT = 'account_limit';
    public const REASON_PROXY = 'proxy_limit';
    public const REASON_BATCH = 'batch_cap';

    public const DEFAULT_BACKOFF_SEC = 15;
    public const DEFAULT_ACCOUNT_LIMIT = 1;
    public const DEFAULT_PROXY_LIMIT = 1;

    /** Redis key for last defer reason (observability; schema stays CONTRACT-clean). */
    public const DEFER_KEY_PREFIX = 'farm:defer:';

    public function __construct(
        private readonly Governor $governor,
        private readonly TaskRepository $tasks,
        private readonly ?AccountRepository $accounts = null,
        private readonly ?Locks $locks = null,
        private readonly RedisClient|MemoryRedis|null $redis = null,
        private readonly int $accountLimit = self::DEFAULT_ACCOUNT_LIMIT,
        private readonly int $proxyLimit = self::DEFAULT_PROXY_LIMIT,
        private readonly int $backoffSec = self::DEFAULT_BACKOFF_SEC,
    ) {
    }

    /**
     * @return array{decision: 'allow'|'defer', reason: string|null}
     */
    public function canAdmit(?int $accountId = null, ?int $proxyId = null, int $n = 1): array
    {
        if ($n <= 0) {
            return ['decision' => self::ALLOW, 'reason' => null];
        }

        $load = $this->governor->sampleLoad();
        if ($load['cpu_pct'] > $this->governor->cpuThreshold()) {
            return ['decision' => self::DEFER, 'reason' => self::REASON_CPU];
        }
        if ($load['ram_pct'] > $this->governor->ramThreshold()) {
            return ['decision' => self::DEFER, 'reason' => self::REASON_RAM];
        }

        $maxChrome = $this->governor->maxChrome();
        // Cap against farm:cfg:max_chrome (Governor.maxChrome reads that key).
        $inflight = $this->inflightChrome();
        if ($inflight + $n > $maxChrome) {
            return ['decision' => self::DEFER, 'reason' => self::REASON_CHROME_CAP];
        }

        // Soft throttle from Governor (CPU/RAM near threshold may lower effective max).
        if ($this->governor->freeSlots() < $n) {
            $reason = self::REASON_CHROME_CAP;
            if ($load['cpu_pct'] >= $this->governor->cpuThreshold() * 0.9) {
                $reason = self::REASON_CPU;
            } elseif ($load['ram_pct'] >= $this->governor->ramThreshold() * 0.9) {
                $reason = self::REASON_RAM;
            }

            return ['decision' => self::DEFER, 'reason' => $reason];
        }

        if ($accountId !== null && $accountId > 0 && $this->accountLimit > 0) {
            if ($this->locks !== null && $this->locks->isAccountLocked($accountId)) {
                return ['decision' => self::DEFER, 'reason' => self::REASON_ACCOUNT];
            }
            $accInflight = $this->tasks->countInflightForAccount($accountId);
            if ($accInflight + $n > $this->accountLimit) {
                return ['decision' => self::DEFER, 'reason' => self::REASON_ACCOUNT];
            }
        }

        $resolvedProxy = $proxyId;
        if (($resolvedProxy === null || $resolvedProxy <= 0) && $accountId !== null && $accountId > 0 && $this->accounts !== null) {
            $account = $this->accounts->findById($accountId);
            if ($account !== null && isset($account['proxy_id'])) {
                $resolvedProxy = (int) $account['proxy_id'];
            }
        }

        if ($resolvedProxy !== null && $resolvedProxy > 0 && $this->proxyLimit > 0) {
            if ($this->locks !== null && $this->locks->isProxyLocked($resolvedProxy)) {
                return ['decision' => self::DEFER, 'reason' => self::REASON_PROXY];
            }
            $pxyInflight = $this->tasks->countInflightForProxy($resolvedProxy);
            if ($pxyInflight + $n > $this->proxyLimit) {
                return ['decision' => self::DEFER, 'reason' => self::REASON_PROXY];
            }
        }

        return ['decision' => self::ALLOW, 'reason' => null];
    }

    /**
     * How many tasks may be admitted immediately (API chunk / scheduler tick).
     */
    public function admitBudget(): int
    {
        $decision = $this->canAdmit(null, null, 1);
        if ($decision['decision'] !== self::ALLOW) {
            return 0;
        }

        $maxChrome = $this->governor->maxChrome();
        $byChrome = max(0, $maxChrome - $this->inflightChrome());
        $byGovernor = $this->governor->freeSlots();

        return min($byChrome, $byGovernor);
    }

    public function backoffSeconds(?string $reason = null): int
    {
        return match ($reason) {
            self::REASON_CPU, self::REASON_RAM => max($this->backoffSec, 30),
            self::REASON_ACCOUNT, self::REASON_PROXY => max($this->backoffSec, 20),
            default => max(1, $this->backoffSec),
        };
    }

    public function rememberDeferReason(int $taskId, string $reason): void
    {
        if ($this->redis === null || $taskId <= 0) {
            return;
        }
        try {
            $this->redis->set(self::DEFER_KEY_PREFIX . $taskId, $reason, 86400);
        } catch (\Throwable) {
            // observability only
        }
    }

    public function clearDeferReason(int $taskId): void
    {
        if ($this->redis === null || $taskId <= 0) {
            return;
        }
        try {
            $this->redis->del(self::DEFER_KEY_PREFIX . $taskId);
        } catch (\Throwable) {
        }
    }

    public function deferReason(int $taskId): ?string
    {
        if ($this->redis === null || $taskId <= 0) {
            return null;
        }
        try {
            $v = $this->redis->get(self::DEFER_KEY_PREFIX . $taskId);

            return ($v !== null && $v !== '') ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * queued + running occupy Chrome slots once workers drain the list.
     */
    public function inflightChrome(): int
    {
        return $this->tasks->countInflight();
    }
}
