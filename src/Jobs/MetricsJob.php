<?php

declare(strict_types=1);

namespace Farm\Jobs;

use Farm\Repositories\MetricRepository;
use Farm\Services\Governor;
use Farm\Support\RedisClient;

/**
 * Collect host / farm metrics into `metrics` every monitor tick (default 15s).
 *
 * Names: cpu, ram, disk, chrome_count (entity_type = system).
 */
final class MetricsJob
{
    public const ENTITY_TYPE = 'system';

    public const NAMES = ['cpu', 'ram', 'disk', 'chrome_count'];

    public function __construct(
        private readonly MetricRepository $metrics,
        private readonly Governor $governor,
        private readonly ?RedisClient $redis = null,
        private readonly string $diskPath = '/',
    ) {
    }

    /**
     * Sample + persist.
     *
     * @return array{
     *     cpu: float,
     *     ram: float,
     *     disk: float,
     *     chrome_count: int,
     *     captured_at: string,
     *     ids: array<string, int>
     * }
     */
    public function run(): array
    {
        $sample = $this->sample();
        $maxChrome = $this->governor->maxChrome();
        $ids = [];
        foreach (self::NAMES as $name) {
            $value = $sample[$name];
            $unit = match ($name) {
                'cpu', 'ram', 'disk' => 'pct',
                'chrome_count' => 'count',
                default => null,
            };
            $ids[$name] = $this->metrics->record(
                self::ENTITY_TYPE,
                null,
                $name,
                $value,
                $unit,
                [
                    'source' => 'metrics_job',
                    'max_chrome' => $maxChrome,
                ]
            );
        }

        $this->mirrorToRedis($sample);

        return $sample + ['ids' => $ids];
    }

    /**
     * @return array{cpu: float, ram: float, disk: float, chrome_count: int, captured_at: string}
     */
    public function sample(): array
    {
        $load = $this->governor->sampleLoad();

        return [
            'cpu' => round((float) $load['cpu_pct'], 2),
            'ram' => round((float) $load['ram_pct'], 2),
            'disk' => round($this->readDiskPercent(), 2),
            'chrome_count' => $this->governor->liveChrome(),
            'captured_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ];
    }

    private function readDiskPercent(): float
    {
        $path = $this->diskPath;
        if ($path === '' || (!is_dir($path) && !is_file($path))) {
            $path = DIRECTORY_SEPARATOR === '\\' ? 'C:\\' : '/';
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return 0.0;
        }

        return max(0.0, min(100.0, (1.0 - ((float) $free / (float) $total)) * 100.0));
    }

    /**
     * @param array{cpu: float, ram: float, disk: float, chrome_count: int, captured_at: string} $sample
     */
    private function mirrorToRedis(array $sample): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->set(
                'farm:cfg:metrics_latest',
                json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                60
            );
        } catch (\Throwable) {
            // Redis optional for persistence path
        }
    }
}
