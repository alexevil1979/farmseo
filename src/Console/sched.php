#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scheduler / Governor CLI: sched:run | sched:stats | governor:status | gc:run
 *
 * Usage:
 *   php farm sched:run [--loop] [--sleep=5] [--once]
 *   php farm sched:stats
 *   php farm governor:status
 *   php farm gc:run
 */

use Farm\Db\Connection;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'sched.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = $raw;
if (str_starts_with($raw, 'sched:')) {
    $command = 'sched:' . substr($raw, 6);
} elseif (str_starts_with($raw, 'governor:')) {
    $command = $raw;
} elseif (str_starts_with($raw, 'gc:')) {
    $command = $raw;
} elseif (in_array($raw, ['run', 'stats'], true)) {
    $command = 'sched:' . $raw;
} elseif ($raw === 'status') {
    $command = 'governor:status';
}
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'sched:run', 'run' => schedCmdRun($factory, $args),
    'sched:stats', 'stats' => schedCmdStats($factory),
    'governor:status', 'status' => schedCmdGovernorStatus($factory),
    'gc:run', 'gc' => schedCmdGc($factory),
    'help', '--help', '-h' => schedCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown sched command: {$command}\n");
        schedCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_SCHED_AS_LIBRARY']) && $_SERVER['FARM_SCHED_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function schedCmdRun(ServiceFactory $factory, array $args): int
{
    $loop = false;
    $sleep = 5;
    foreach ($args as $arg) {
        if ($arg === '--loop') {
            $loop = true;
        } elseif ($arg === '--once') {
            $loop = false;
        } elseif (preg_match('/^--sleep=(\d+)$/', $arg, $m)) {
            $sleep = max(1, (int) $m[1]);
        }
    }

    $scheduler = $factory->scheduler();
    $scheduler->bootstrapNextRuns();

    do {
        $stats = $scheduler->run();
        echo sprintf(
            "[sched] fired=%d created=%d promoted=%d enqueued=%d night=%d locked=%d throttle=%d q=%d chrome=%d/%d cpu=%.1f%% %s\n",
            $stats['schedules_fired'],
            $stats['tasks_created'],
            $stats['retries_promoted'],
            $stats['enqueued'],
            $stats['skipped_night'],
            $stats['skipped_locked'],
            $stats['skipped_throttle'],
            $stats['queue_len'],
            $stats['governor']['live_chrome'] ?? 0,
            $stats['governor']['max_chrome'] ?? 0,
            $stats['governor']['cpu_pct'] ?? 0,
            ($stats['governor']['throttled'] ?? false) ? 'THROTTLED:' . ($stats['governor']['reason'] ?? '') : 'ok'
        );

        if ($loop) {
            sleep($sleep);
        }
    } while ($loop);

    return 0;
}

function schedCmdStats(ServiceFactory $factory): int
{
    $tasks = $factory->taskRepository();
    $byStatus = $tasks->countByStatus();
    $gov = $factory->governor()->status();
    $q = 0;
    try {
        $q = $factory->redis()->llen('farm:q:tasks');
    } catch (Throwable $e) {
        fwrite(STDERR, 'Redis: ' . $e->getMessage() . "\n");
    }

    $locks = [];
    try {
        $locks = $factory->accountLocks()->listLocks();
    } catch (Throwable) {
        $locks = [];
    }

    echo "Scheduler stats\n";
    echo "  queue farm:q:tasks: {$q}\n";
    echo "  tasks by status:\n";
    foreach (['pending', 'queued', 'running', 'retrying', 'done', 'failed', 'cancelled'] as $s) {
        $c = $byStatus[$s] ?? 0;
        echo "    {$s}: {$c}\n";
    }
    echo "  redis locks: " . count($locks) . "\n";
    echo "  governor:\n";
    echo "    live_chrome: {$gov['live_chrome']} / max {$gov['max_chrome']} (effective {$gov['effective_max']})\n";
    echo "    free_slots:  {$gov['free_slots']}\n";
    echo "    cpu:         {$gov['cpu_pct']}% (threshold {$gov['cpu_threshold']}%)\n";
    echo "    ram:         {$gov['ram_pct']}% (threshold {$gov['ram_threshold']}%)\n";
    echo '    throttled:   ' . ($gov['throttled'] ? 'yes (' . $gov['reason'] . ')' : 'no') . "\n";

    return 0;
}

function schedCmdGovernorStatus(ServiceFactory $factory): int
{
    $gov = $factory->governor()->status();
    echo json_encode($gov, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    return $gov['throttled'] ? 2 : 0;
}

function schedCmdGc(ServiceFactory $factory): int
{
    $result = $factory->gcJob()->run();
    echo "GC done\n";
    echo "  stale_tasks:        {$result['stale_tasks']}\n";
    echo "  retried:            {$result['retried']}\n";
    echo "  failed:             {$result['failed']}\n";
    echo "  locks_acc_cleared:  {$result['locks_cleared']['accounts']}\n";
    echo "  locks_pxy_cleared:  {$result['locks_cleared']['proxies']}\n";
    echo "  singletons_removed: {$result['singletons_removed']}\n";
    echo "  chrome_killed:      {$result['chrome_killed']}\n";
    echo "  worker_hb_keys:     {$result['dead_workers']}\n";

    return 0;
}

function schedCmdHelp(): int
{
    echo <<<'HELP'
Scheduler / Governor CLI

Commands:
  sched:run [--loop] [--sleep=5]
      Fire due scenario_schedules, promote retries, enqueue to farm:q:tasks
      respecting night windows, locks, CPU/RAM/chrome caps

  sched:stats
      Queue length, task status counts, governor snapshot

  governor:status
      JSON status (exit 2 when throttled)

  gc:run
      Reclaim stale running tasks, clear orphan locks/SingletonLock, kill zombie Chrome

HELP;

    return 0;
}
