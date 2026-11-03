<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\SettingRepository;
use Farm\Repositories\TaskRepository;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;

/**
 * Decides WHETHER and HOW MANY new Chrome tasks may start.
 *
 * - Concurrent Chrome cap (default 40 via MAX_CHROME)
 * - CPU / RAM thresholds → auto-throttle (no permanent block; recovers on next tick)
 * - Priority: higher-priority pending tasks preferred by Scheduler ordering
 *
 * Redis mirrors: farm:cfg:max_chrome, farm:cfg:governor_state
 */
final class Governor
{
    public const DEFAULT_MAX_CHROME = 40;
    public const DEFAULT_CPU_PCT = 80.0;
    public const DEFAULT_RAM_PCT = 90.0;
    public const CFG_MAX_CHROME = 'farm:cfg:max_chrome';
    public const CFG_STATE = 'farm:cfg:governor_state';

    private ?float $cpuOverride = null;
    private ?float $ramOverride = null;
    private ?int $liveChromeOverride = null;

    public function __construct(
        private readonly RedisClient|MemoryRedis $redis,
        private readonly TaskRepository $tasks,
        private readonly ?SettingRepository $settings = null,
        private readonly int $defaultMaxChrome = self::DEFAULT_MAX_CHROME,
        private readonly float $cpuThresholdPct = self::DEFAULT_CPU_PCT,
        private readonly float $ramThresholdPct = self::DEFAULT_RAM_PCT,
    ) {
    }

    /** Test / inject hooks */
    public function setLoadOverride(?float $cpuPct, ?float $ramPct = null): void
    {
        $this->cpuOverride = $cpuPct;
        $this->ramOverride = $ramPct;
    }

    public function setLiveChromeOverride(?int $n): void
    {
        $this->liveChromeOverride = $n;
    }

    public function maxChrome(): int
    {
        try {
            $cached = $this->redis->get(self::CFG_MAX_CHROME);
            if ($cached !== null && ctype_digit($cached)) {
                return max(1, (int) $cached);
            }
        } catch (\Throwable) {
            // ignore
        }

        if ($this->settings !== null) {
            $row = $this->settings->getByName('max_chrome');
            if ($row !== null) {
                $v = $row['value'];
                if (is_string($v)) {
                    $decoded = json_decode($v, true);
                    $v = $decoded ?? $v;
                }
                if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                    return max(1, (int) $v);
                }
                if (is_array($v) && isset($v['value'])) {
                    return max(1, (int) $v['value']);
                }
            }
        }

        // defaultMaxChrome is wired from MAX_CHROME via ServiceFactory / config
        return max(1, $this->defaultMaxChrome);
    }

    /**
     * Effective cap after throttle (may be lower than maxChrome when load is high).
     */
    public function effectiveMaxChrome(): int
    {
        $max = $this->maxChrome();
        $load = $this->sampleLoad();
        $factor = 1.0;

        if ($load['cpu_pct'] >= $this->cpuThresholdPct) {
            $factor = min($factor, 0.0); // hard stop new starts
        } elseif ($load['cpu_pct'] >= $this->cpuThresholdPct * 0.9) {
            $factor = min($factor, 0.5);
        }

        if ($load['ram_pct'] >= $this->ramThresholdPct) {
            $factor = min($factor, 0.0);
        } elseif ($load['ram_pct'] >= $this->ramThresholdPct * 0.9) {
            $factor = min($factor, 0.5);
        }

        if ($factor <= 0.0) {
            return 0;
        }

        return max(0, (int) floor($max * $factor));
    }

    /**
     * Live Chrome estimate: running tasks (canonical) + account locks as soft upper bound.
     */
    public function liveChrome(): int
    {
        if ($this->liveChromeOverride !== null) {
            return max(0, $this->liveChromeOverride);
        }

        $running = $this->tasks->count(['status' => 'running']);

        return max(0, $running);
    }

    public function freeSlots(): int
    {
        return max(0, $this->effectiveMaxChrome() - $this->liveChrome());
    }

    /**
     * Can we start / enqueue N more Chrome tasks right now?
     */
    public function canAdmit(int $n = 1): bool
    {
        if ($n <= 0) {
            return true;
        }
        $load = $this->sampleLoad();
        if ($load['cpu_pct'] > $this->cpuThresholdPct) {
            $this->persistState(false, 'cpu_throttle', $load);

            return false;
        }
        if ($load['ram_pct'] > $this->ramThresholdPct) {
            $this->persistState(false, 'ram_throttle', $load);

            return false;
        }
        if ($this->freeSlots() < $n) {
            $this->persistState(false, 'chrome_cap', $load);

            return false;
        }

        $this->persistState(true, 'ok', $load);

        return true;
    }

    /**
     * How many new tasks may be admitted this tick (0 when throttled).
     */
    public function admitBudget(): int
    {
        if (!$this->canAdmit(1)) {
            return 0;
        }

        return $this->freeSlots();
    }

    /**
     * @return array{
     *     max_chrome: int,
     *     effective_max: int,
     *     live_chrome: int,
     *     free_slots: int,
     *     cpu_pct: float,
     *     ram_pct: float,
     *     cpu_threshold: float,
     *     ram_threshold: float,
     *     throttled: bool,
     *     reason: string
     * }
     */
    public function status(): array
    {
        $load = $this->sampleLoad();
        $can = $this->canAdmit(1);
        $reason = 'ok';
        if ($load['cpu_pct'] > $this->cpuThresholdPct) {
            $reason = 'cpu_throttle';
        } elseif ($load['ram_pct'] > $this->ramThresholdPct) {
            $reason = 'ram_throttle';
        } elseif ($this->freeSlots() <= 0) {
            $reason = 'chrome_cap';
        }

        return [
            'max_chrome' => $this->maxChrome(),
            'effective_max' => $this->effectiveMaxChrome(),
            'live_chrome' => $this->liveChrome(),
            'free_slots' => $this->freeSlots(),
            'cpu_pct' => round($load['cpu_pct'], 2),
            'ram_pct' => round($load['ram_pct'], 2),
            'cpu_threshold' => $this->cpuThresholdPct,
            'ram_threshold' => $this->ramThresholdPct,
            'throttled' => !$can,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{cpu_pct: float, ram_pct: float}
     */
    public function sampleLoad(): array
    {
        if ($this->cpuOverride !== null || $this->ramOverride !== null) {
            return [
                'cpu_pct' => $this->cpuOverride ?? 0.0,
                'ram_pct' => $this->ramOverride ?? 0.0,
            ];
        }

        return [
            'cpu_pct' => $this->readCpuPercent(),
            'ram_pct' => $this->readRamPercent(),
        ];
    }

    public function cpuThreshold(): float
    {
        return $this->cpuThresholdPct;
    }

    public function ramThreshold(): float
    {
        return $this->ramThresholdPct;
    }

    private function readCpuPercent(): float
    {
        // Linux: loadavg relative to nproc
        if (is_readable('/proc/loadavg') && function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cores = $this->cpuCount();
            if (is_array($load) && isset($load[0]) && $cores > 0) {
                return min(100.0, max(0.0, ((float) $load[0] / $cores) * 100.0));
            }
        }

        // Windows: wmic (best-effort)
        if (PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @exec('wmic cpu get loadpercentage /value 2>NUL', $out);
            foreach ($out as $line) {
                if (preg_match('/LoadPercentage=(\d+)/', $line, $m)) {
                    return (float) $m[1];
                }
            }
        }

        return 0.0;
    }

    private function readRamPercent(): float
    {
        if (is_readable('/proc/meminfo')) {
            $raw = @file_get_contents('/proc/meminfo');
            if (is_string($raw)
                && preg_match('/MemTotal:\s+(\d+)/', $raw, $t)
                && preg_match('/MemAvailable:\s+(\d+)/', $raw, $a)
            ) {
                $total = (float) $t[1];
                $avail = (float) $a[1];
                if ($total > 0) {
                    return max(0.0, min(100.0, (1.0 - ($avail / $total)) * 100.0));
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>NUL', $out);
            $free = null;
            $total = null;
            foreach ($out as $line) {
                if (preg_match('/FreePhysicalMemory=(\d+)/', $line, $m)) {
                    $free = (float) $m[1];
                }
                if (preg_match('/TotalVisibleMemorySize=(\d+)/', $line, $m)) {
                    $total = (float) $m[1];
                }
            }
            if ($free !== null && $total !== null && $total > 0) {
                return max(0.0, min(100.0, (1.0 - ($free / $total)) * 100.0));
            }
        }

        return 0.0;
    }

    private function cpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $raw = (string) @file_get_contents('/proc/cpuinfo');
            $n = substr_count($raw, 'processor');
            if ($n > 0) {
                return $n;
            }
        }
        $n = (int) (getenv('NUMBER_OF_PROCESSORS') ?: 0);
        if ($n > 0) {
            return $n;
        }

        return 1;
    }

    /**
     * @param array{cpu_pct: float, ram_pct: float} $load
     */
    private function persistState(bool $ok, string $reason, array $load): void
    {
        $payload = json_encode([
            'ok' => $ok,
            'reason' => $reason,
            'cpu_pct' => $load['cpu_pct'],
            'ram_pct' => $load['ram_pct'],
            'live_chrome' => $this->liveChromeOverride ?? -1,
            'max_chrome' => $this->defaultMaxChrome,
            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        try {
            $this->redis->set(self::CFG_STATE, $payload, 60);
            $this->redis->set(self::CFG_MAX_CHROME, (string) $this->maxChrome(), 300);
        } catch (\Throwable) {
            // Redis optional for decision path; tick continues.
        }
    }
}
