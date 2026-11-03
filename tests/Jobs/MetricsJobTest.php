<?php

declare(strict_types=1);

namespace Farm\Tests\Jobs;

use Farm\Jobs\MetricsJob;
use Farm\Repositories\MetricRepository;
use Farm\Repositories\TaskRepository;
use Farm\Services\Governor;
use Farm\Support\RedisClient;
use Farm\Tests\TestCase;

final class MetricsJobTest extends TestCase
{
    public function testCollectPersistsCpuRamDiskChrome(): void
    {
        $metrics = new MetricRepository($this->db);
        $gov = new Governor(
            new RedisClient(['host' => '127.0.0.1', 'port' => 1]),
            new TaskRepository($this->db),
            null,
            40,
            80.0,
            90.0,
        );
        $gov->setLoadOverride(12.5, 34.0);
        $gov->setLiveChromeOverride(3);

        $job = new MetricsJob($metrics, $gov, null, DIRECTORY_SEPARATOR === '\\' ? 'C:\\' : '/');
        $result = $job->run();

        self::assertSame(12.5, $result['cpu']);
        self::assertSame(34.0, $result['ram']);
        self::assertSame(3, $result['chrome_count']);
        self::assertIsFloat($result['disk']);
        self::assertArrayHasKey('cpu', $result['ids']);

        $snap = $metrics->latestSnapshot(MetricsJob::NAMES, MetricsJob::ENTITY_TYPE);
        self::assertSame(12.5, $snap['cpu']);
        self::assertSame(34.0, $snap['ram']);
        self::assertSame(3.0, $snap['chrome_count']);
        self::assertNotNull($snap['disk']);
        self::assertNotNull($snap['captured_at']);
    }
}
