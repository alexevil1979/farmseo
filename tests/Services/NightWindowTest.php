<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use DateTimeImmutable;
use DateTimeZone;
use Farm\Services\NightWindow;
use PHPUnit\Framework\TestCase;

final class NightWindowTest extends TestCase
{
    public function testNightCrossesMidnightInAccountTz(): void
    {
        $nw = new NightWindow(null, '23:00', '07:00');

        // 02:00 Moscow = night
        $night = new DateTimeImmutable('2026-10-28 02:00:00', new DateTimeZone('Europe/Moscow'));
        self::assertTrue($nw->isNight('Europe/Moscow', $night));

        // 14:00 Moscow = day
        $day = new DateTimeImmutable('2026-10-28 14:00:00', new DateTimeZone('Europe/Moscow'));
        self::assertFalse($nw->isNight('Europe/Moscow', $day));
    }

    public function testSameWindowDifferentTz(): void
    {
        $nw = new NightWindow(null, '23:00', '07:00');
        // UTC 20:00 → New York 16:00 (EDT) = day; Tokyo 05:00 = night
        $utc = new DateTimeImmutable('2026-06-15 20:00:00', new DateTimeZone('UTC'));
        self::assertFalse($nw->isNight('America/New_York', $utc));
        self::assertTrue($nw->isNight('Asia/Tokyo', $utc));
    }

    public function testDisabledWhenStartEqualsEnd(): void
    {
        $nw = new NightWindow(null, '00:00', '00:00');
        $now = new DateTimeImmutable('2026-10-28 03:00:00', new DateTimeZone('UTC'));
        self::assertFalse($nw->isNight('UTC', $now));
    }
}
