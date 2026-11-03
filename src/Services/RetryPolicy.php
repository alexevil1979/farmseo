<?php

declare(strict_types=1);

namespace Farm\Services;

use DateInterval;
use DateTimeImmutable;
use Farm\Repositories\TaskRepository;

/**
 * Exponential backoff + max_attempts → failed.
 *
 * Status flow: running/queued → retrying (scheduled_at = now+delay) → pending → … → failed
 */
final class RetryPolicy
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly int $baseDelaySec = 30,
        private readonly int $maxDelaySec = 900,
        private readonly float $multiplier = 2.0,
        private readonly float $jitterRatio = 0.2,
    ) {
    }

    /**
     * Delay in seconds for the N-th retry attempt (1-based after failure).
     */
    public function delaySeconds(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $raw = (int) round($this->baseDelaySec * ($this->multiplier ** ($attempt - 1)));
        $capped = min($this->maxDelaySec, max($this->baseDelaySec, $raw));

        if ($this->jitterRatio <= 0) {
            return $capped;
        }

        $jitter = (int) round($capped * $this->jitterRatio);
        if ($jitter <= 0) {
            return $capped;
        }

        return max(1, $capped + random_int(-$jitter, $jitter));
    }

    /**
     * @param array<string, mixed> $task
     */
    public function shouldRetry(array $task): bool
    {
        $attempts = (int) ($task['attempts'] ?? 0);
        $max = (int) ($task['max_attempts'] ?? 3);

        return $attempts < $max;
    }

    /**
     * Record a failure: bump attempts; schedule retry or mark failed.
     *
     * @param array<string, mixed> $task
     * @return array{status: string, attempts: int, delay_sec: int|null, scheduled_at: string|null}
     */
    public function applyFailure(array $task, string $error, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $id = (int) $task['id'];
        $attempts = (int) ($task['attempts'] ?? 0) + 1;
        $max = (int) ($task['max_attempts'] ?? 3);
        $task['attempts'] = $attempts;

        if ($attempts >= $max) {
            $this->tasks->update($id, [
                'status' => 'failed',
                'attempts' => $attempts,
                'error' => $error,
                'finished_at' => $now->format('Y-m-d H:i:s.v'),
                'scheduled_at' => null,
            ]);

            return [
                'status' => 'failed',
                'attempts' => $attempts,
                'delay_sec' => null,
                'scheduled_at' => null,
            ];
        }

        $delay = $this->delaySeconds($attempts);
        $scheduled = $now->add(new DateInterval('PT' . $delay . 'S'));
        $scheduledAt = $scheduled->format('Y-m-d H:i:s.v');

        $this->tasks->update($id, [
            'status' => 'retrying',
            'attempts' => $attempts,
            'error' => $error,
            'scheduled_at' => $scheduledAt,
            'finished_at' => null,
            'started_at' => null,
        ]);

        return [
            'status' => 'retrying',
            'attempts' => $attempts,
            'delay_sec' => $delay,
            'scheduled_at' => $scheduledAt,
        ];
    }

    /**
     * Promote due retrying tasks back to pending so Scheduler can enqueue them.
     *
     * @return int number promoted
     */
    public function promoteDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now');
        $stamp = $now->format('Y-m-d H:i:s.v');
        $due = $this->tasks->findRetryingDue($stamp);
        $n = 0;
        foreach ($due as $task) {
            $ok = $this->tasks->update((int) $task['id'], [
                'status' => 'pending',
            ]);
            if ($ok) {
                $n++;
            }
        }

        return $n;
    }
}
