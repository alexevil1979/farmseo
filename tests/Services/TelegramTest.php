<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Services\Telegram;
use Farm\Tests\TestCase;

final class TelegramTest extends TestCase
{
    public function testFormatAndSeverityLevels(): void
    {
        $tg = new Telegram(null, null, 't', '1');
        self::assertSame('critical', $tg->normalizeSeverity('crit'));
        self::assertSame('warn', $tg->normalizeSeverity('warning'));
        self::assertSame('info', $tg->normalizeSeverity('info'));

        $text = $tg->formatMessage('critical', 'alert_engine', 'Worker down', [
            'rule' => 'worker_down',
            'worker_id' => 'w-1',
        ]);
        self::assertStringContainsString('CRITICAL', $text);
        self::assertStringContainsString('Worker down', $text);
        self::assertStringContainsString('worker\_down', $text);
    }

    public function testDedupeWithoutRedisSendsTwice(): void
    {
        $calls = 0;
        $tg = new Telegram(
            null,
            null,
            't',
            '1',
            300,
            static function () use (&$calls): array {
                $calls++;

                return ['ok' => true, 'result' => ['message_id' => $calls]];
            }
        );

        $a = $tg->sendAlert('warn', 'test', 'same message', ['rule' => 'x']);
        $b = $tg->sendAlert('warn', 'test', 'same message', ['rule' => 'x']);
        self::assertTrue($a['sent']);
        self::assertTrue($b['sent']);
        self::assertSame(2, $calls);
    }

    public function testSendTestRequiresConfig(): void
    {
        $tg = new Telegram();
        $r = $tg->sendTest();
        self::assertFalse($r['sent']);
        self::assertSame('telegram_not_configured', $r['error']);
    }

    public function testSendAlertUsesHttpClient(): void
    {
        $seen = null;
        $tg = new Telegram(
            null,
            null,
            'tok',
            '42',
            60,
            static function (string $method, array $params) use (&$seen): array {
                $seen = [$method, $params];

                return ['ok' => true, 'result' => ['message_id' => 99]];
            }
        );
        $r = $tg->sendAlert('info', 'monitor', 'hello');
        self::assertTrue($r['sent']);
        self::assertSame(99, $r['message_id']);
        self::assertSame('sendMessage', $seen[0]);
        self::assertSame('42', $seen[1]['chat_id']);
    }
}
