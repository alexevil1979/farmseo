<?php

declare(strict_types=1);

namespace Farm\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Farm\Support\CronExpression;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    public function testEveryMinuteIsDue(): void
    {
        $cron = CronExpression::parse('* * * * *');
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-28 12:34:00')));
    }

    public function testSpecificMinuteHour(): void
    {
        $cron = CronExpression::parse('15 10 * * *');
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-28 10:15:00')));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-10-28 10:16:00')));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-10-28 11:15:00')));
    }

    public function testStepMinutes(): void
    {
        $cron = CronExpression::parse('*/5 * * * *');
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-28 10:00:00')));
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-28 10:25:00')));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-10-28 10:26:00')));
    }

    public function testNextRunAdvances(): void
    {
        $cron = CronExpression::parse('0 * * * *');
        $from = new DateTimeImmutable('2026-10-28 10:15:00', new DateTimeZone('UTC'));
        $next = $cron->nextRun($from, new DateTimeZone('UTC'));
        self::assertSame('2026-10-28 11:00:00', $next->format('Y-m-d H:i:s'));
    }
}
