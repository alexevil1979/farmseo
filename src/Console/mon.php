#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Monitor CLI: mon:watch | mon:test-alert
 *
 * Usage:
 *   php farm mon:watch [--once] [--sleep=15]
 *   php farm mon:test-alert [--message=...]
 */

use Farm\Db\Connection;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'mon.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = $raw;
if (str_starts_with($raw, 'mon:')) {
    $command = $raw;
} elseif (in_array($raw, ['watch', 'test-alert', 'test'], true)) {
    $command = 'mon:' . ($raw === 'test' ? 'test-alert' : $raw);
}
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'mon:watch', 'watch' => monCmdWatch($factory, $args),
    'mon:test-alert', 'test-alert', 'test' => monCmdTestAlert($factory, $args),
    'help', '--help', '-h' => monCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown mon command: {$command}\n");
        monCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_MON_AS_LIBRARY']) && $_SERVER['FARM_MON_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function monCmdWatch(ServiceFactory $factory, array $args): int
{
    $loop = true;
    $sleep = (int) $factory->configValue('monitor.interval_sec', 15);
    foreach ($args as $arg) {
        if ($arg === '--once') {
            $loop = false;
        } elseif ($arg === '--loop') {
            $loop = true;
        } elseif (preg_match('/^--sleep=(\d+)$/', $arg, $m)) {
            $sleep = max(1, (int) $m[1]);
        }
    }

    do {
        $started = microtime(true);
        try {
            $metricsJob = $factory->metricsJob();
            $sample = $metricsJob->run();
            $result = $factory->alertEngine()->evaluate($sample);

            $firedN = count($result['fired']);
            printf(
                "[%s] metrics cpu=%.1f ram=%.1f disk=%.1f chrome=%d | workers=%d down=%d alerts=%d\n",
                (new DateTimeImmutable())->format('H:i:s'),
                $sample['cpu'],
                $sample['ram'],
                $sample['disk'],
                $sample['chrome_count'],
                count($result['workers_alive']),
                count($result['workers_down']),
                $firedN
            );
            foreach ($result['fired'] as $f) {
                $tg = $f['telegram']['sent'] ?? false ? 'tg:ok' : (
                    ($f['telegram']['deduped'] ?? false) ? 'tg:deduped' : 'tg:skip'
                );
                printf(
                    "  -> [%s] %s %s (%s)\n",
                    $f['severity'] ?? '?',
                    $f['rule'] ?? '?',
                    $f['message'] ?? '',
                    $tg
                );
            }

            try {
                $idleStopped = $factory->liveView()->stopIdle();
                if ($idleStopped !== []) {
                    printf("  -> live-view idle stop: accounts %s\n", implode(',', $idleStopped));
                }
            } catch (Throwable $liveErr) {
                fwrite(STDERR, '[mon] live-view idle: ' . $liveErr->getMessage() . "\n");
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[mon] error: ' . $e->getMessage() . "\n");
            if (!$loop) {
                return 1;
            }
        }

        if (!$loop) {
            break;
        }
        $elapsed = microtime(true) - $started;
        $wait = max(0, $sleep - $elapsed);
        if ($wait > 0) {
            usleep((int) round($wait * 1_000_000));
        }
    } while ($loop);

    return 0;
}

/**
 * @param list<string> $args
 */
function monCmdTestAlert(ServiceFactory $factory, array $args): int
{
    $message = 'Farm monitor test alert';
    foreach ($args as $arg) {
        if (preg_match('/^--message=(.+)$/', $arg, $m)) {
            $message = $m[1];
        }
    }

    $tg = $factory->telegram();
    if (!$tg->isConfigured()) {
        fwrite(STDERR, "Telegram not configured (set TG_TOKEN and TG_CHAT_ID).\n");

        return 1;
    }

    $result = $tg->sendTest($message);
    if (!($result['sent'] ?? false)) {
        fwrite(STDERR, 'Test alert failed: ' . ($result['error'] ?? 'unknown') . "\n");

        return 1;
    }

    echo 'Test alert sent' . (isset($result['message_id']) ? " (message_id={$result['message_id']})" : '') . "\n";

    // Also persist a sample alert row for panel visibility
    $factory->alertRepository()->create([
        'severity' => 'info',
        'source' => 'monitor',
        'message' => $message,
        'is_acked' => 0,
        'meta' => ['rule' => 'test_alert', 'telegram_message_id' => $result['message_id'] ?? null],
    ]);

    return 0;
}

function monCmdHelp(): int
{
    echo <<<'HELP'
Monitor CLI — metrics + alerts + Telegram

Usage:
  php farm mon:watch [--once] [--sleep=15]
  php farm mon:test-alert [--message=text]

Commands:
  mon:watch         Collect cpu/ram/disk/chrome_count every N seconds; evaluate alerts
  mon:test-alert    Send a Telegram test message (requires TG_TOKEN + TG_CHAT_ID)

HELP;

    return 0;
}
