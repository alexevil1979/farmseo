#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scenario CLI: scenario:run | scenario:sync | scenario:event | scenario:import-events
 *
 * Usage:
 *   php farm scenario:run --account=1 --scenario=warmup [--day=1] [--fast]
 *   php farm scenario:sync --scenario=warmup
 *   php farm scenario:event --run=ID --event=step_ok --level=info --message=...
 */

use Farm\Db\Connection;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\RunsEventRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\ScenarioStepRepository;
use Farm\Repositories\TaskRepository;
use Farm\Repositories\TaskRunRepository;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'scenario.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = str_starts_with($raw, 'scenario:') ? substr($raw, 9) : $raw;
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);

$exit = match ($command) {
    'run' => scenarioCmdRun($db, $config, $root, $args),
    'sync' => scenarioCmdSync($db, $root, $args),
    'event' => scenarioCmdEvent($db, $args),
    'import-events' => scenarioCmdImportEvents($db, $args),
    'help', '--help', '-h' => scenarioCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown scenario command: {$command}\n");
        scenarioCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_SCENARIO_AS_LIBRARY']) && $_SERVER['FARM_SCENARIO_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function scenarioCmdRun(Connection $db, array $config, string $root, array $args): int
{
    $opts = scenarioParseArgs($args);
    $accountId = isset($opts['account']) ? (int) $opts['account'] : 0;
    $scenarioName = isset($opts['scenario']) ? (string) $opts['scenario'] : 'warmup';
    $day = isset($opts['day']) ? (int) $opts['day'] : 1;
    $fast = isset($opts['fast']);

    if ($accountId <= 0) {
        fwrite(STDERR, "Usage: scenario:run --account=ID --scenario=warmup|pf|service [--day=N] [--fast]\n");

        return 1;
    }

    $accounts = new AccountRepository($db);
    $account = $accounts->findById($accountId);
    if ($account === null) {
        fwrite(STDERR, "Account {$accountId} not found\n");

        return 1;
    }

    $scenarioId = scenarioEnsureSynced($db, $root, $scenarioName);
    if ($scenarioId === null) {
        fwrite(STDERR, "Failed to sync scenario {$scenarioName}\n");

        return 1;
    }

    $scenarios = new ScenarioRepository($db);
    $scenario = $scenarios->findById($scenarioId);
    $type = (string) ($scenario['type'] ?? 'warmup');

    $tasks = new TaskRepository($db);
    $runs = new TaskRunRepository($db);
    $events = new RunsEventRepository($db);

    $payload = [
        'type' => $type,
        'account_id' => $accountId,
        'scenario_id' => $scenarioId,
        'scenario_name' => $scenarioName,
        'target_url' => $opts['target-url'] ?? null,
        'keyword' => $opts['keyword'] ?? null,
        'geo' => $opts['geo'] ?? 'ru',
        'device' => $opts['device'] ?? 'desktop',
        'day' => $day,
        'limits' => $fast ? ['fast' => true] : [],
    ];

    $taskId = $tasks->create([
        'type' => $type,
        'priority' => 5,
        'status' => 'running',
        'account_id' => $accountId,
        'scenario_id' => $scenarioId,
        'payload' => $payload,
        'attempts' => 1,
        'max_attempts' => 1,
        'timeout' => isset($opts['timeout']) ? (int) $opts['timeout'] : 1800,
        'started_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
    ]);

    $runId = $runs->create([
        'task_id' => $taskId,
        'attempt' => 1,
        'worker_id' => 'cli-scenario',
        'status' => 'running',
        'metrics' => [],
        'screenshots' => [],
    ]);

    $events->append($runId, 'cli_start', 'info', "scenario:run {$scenarioName}", [
        'account_id' => $accountId,
        'day' => $day,
        'fast' => $fast,
    ]);

    $node = $opts['node'] ?? 'node';
    $cli = $root . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'scenario' . DIRECTORY_SEPARATOR . 'cli.js';
    if (!is_file($cli)) {
        fwrite(STDERR, "Missing {$cli}\n");

        return 1;
    }

    $cmd = [
        $node,
        $cli,
        'run',
        "--account={$accountId}",
        "--scenario={$scenarioName}",
        "--day={$day}",
        "--task={$taskId}",
        "--task-run={$runId}",
        "--scenario-id={$scenarioId}",
    ];
    if ($fast) {
        $cmd[] = '--fast';
    }
    if (!empty($opts['keyword'])) {
        $cmd[] = '--keyword=' . $opts['keyword'];
    }
    if (!empty($opts['target-url'])) {
        $cmd[] = '--target-url=' . $opts['target-url'];
    }
    if (isset($opts['headless'])) {
        $cmd[] = '--headless=' . $opts['headless'];
    }

    echo 'Running: ' . implode(' ', $cmd) . PHP_EOL;

    $descriptors = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $root . DIRECTORY_SEPARATOR . 'workers');
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start node scenario runner\n");
        $runs->update($runId, [
            'status' => 'failed',
            'error' => 'failed to spawn node',
            'finished_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
        $tasks->markFinished($taskId, 'failed', 'failed to spawn node');

        return 1;
    }
    $code = proc_close($proc);

    $summaryPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
        . DIRECTORY_SEPARATOR . 'scenarios' . DIRECTORY_SEPARATOR . "summary-{$runId}.json";
    $summary = is_file($summaryPath)
        ? json_decode((string) file_get_contents($summaryPath), true)
        : null;

    $status = is_array($summary) && isset($summary['status'])
        ? (string) $summary['status']
        : ($code === 0 ? 'done' : 'failed');
    $error = is_array($summary) ? ($summary['error'] ?? null) : ($code === 0 ? null : "exit {$code}");
    $metrics = is_array($summary) ? ($summary['metrics'] ?? []) : [];
    $screenshots = is_array($summary) ? ($summary['screenshots'] ?? []) : [];

    // Replace step log from NDJSON (authoritative step-by-step trail)
    $logPath = is_array($summary) && !empty($summary['log_path'])
        ? (string) $summary['log_path']
        : $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'scenarios' . DIRECTORY_SEPARATOR . "run-{$runId}.ndjson";
    if (is_file($logPath)) {
        $db->execute(
            'DELETE FROM `runs_events` WHERE `task_run_id` = :id AND `event` NOT IN (\'cli_start\')',
            [':id' => $runId]
        );
        scenarioImportNdjson($events, $runId, $logPath);
    }

    $runs->update($runId, [
        'status' => $status === 'done' ? 'done' : 'failed',
        'error' => $error,
        'metrics' => $metrics,
        'screenshots' => $screenshots,
        'finished_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
    ]);
    $tasks->markFinished($taskId, $status === 'done' ? 'done' : 'failed', $error);

    $events->append($runId, 'cli_finish', $status === 'done' ? 'info' : 'error', "status={$status}", [
        'exit_code' => $code,
    ]);

    echo "task_id={$taskId} task_run_id={$runId} status={$status}\n";

    return $status === 'done' ? 0 : 2;
}

/**
 * @param list<string> $args
 */
function scenarioCmdSync(Connection $db, string $root, array $args): int
{
    $opts = scenarioParseArgs($args);
    $name = isset($opts['scenario']) ? (string) $opts['scenario'] : ($opts['_'][0] ?? '');
    if ($name === '') {
        // sync all json
        $dir = $root . DIRECTORY_SEPARATOR . 'scenarios';
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $n = 0;
        foreach ($files as $file) {
            $stem = basename($file, '.json');
            $id = scenarioEnsureSynced($db, $root, $stem);
            if ($id !== null) {
                echo "synced {$stem} id={$id}\n";
                $n++;
            }
        }
        echo "synced {$n} scenario(s)\n";

        return 0;
    }

    $id = scenarioEnsureSynced($db, $root, $name);
    if ($id === null) {
        fwrite(STDERR, "Cannot sync scenario {$name}\n");

        return 1;
    }
    echo "synced {$name} id={$id}\n";

    return 0;
}

function scenarioEnsureSynced(Connection $db, string $root, string $name): ?int
{
    $file = $root . DIRECTORY_SEPARATOR . 'scenarios' . DIRECTORY_SEPARATOR . $name . '.json';
    if (!is_file($file)) {
        fwrite(STDERR, "Scenario file missing: {$file}\n");

        return null;
    }
    $raw = json_decode((string) file_get_contents($file), true);
    if (!is_array($raw) || empty($raw['name']) || empty($raw['type']) || empty($raw['steps'])) {
        fwrite(STDERR, "Invalid scenario JSON: {$file}\n");

        return null;
    }

    $scenarios = new ScenarioRepository($db);
    $steps = new ScenarioStepRepository($db);
    $version = (int) ($raw['version'] ?? 1);
    $existing = $scenarios->findByNameVersion((string) $raw['name'], $version);

    if ($existing === null) {
        $scenarioId = $scenarios->create([
            'name' => (string) $raw['name'],
            'type' => (string) $raw['type'],
            'description' => $raw['description'] ?? null,
            'version' => $version,
            'is_active' => 1,
            'defaults' => $raw['defaults'] ?? null,
        ]);
    } else {
        $scenarioId = (int) $existing['id'];
        $scenarios->update($scenarioId, [
            'description' => $raw['description'] ?? null,
            'is_active' => 1,
            'defaults' => $raw['defaults'] ?? null,
        ]);
        // replace steps
        $db->execute('DELETE FROM `scenario_steps` WHERE `scenario_id` = :id', [':id' => $scenarioId]);
    }

    $order = 1;
    foreach ($raw['steps'] as $step) {
        if (!is_array($step) || empty($step['type'])) {
            continue;
        }
        $type = (string) $step['type'];
        $timeout = isset($step['timeout_sec']) ? (int) $step['timeout_sec'] : null;
        $onFail = isset($step['on_fail']) ? (string) $step['on_fail'] : 'abort';
        $params = $step;
        unset($params['timeout_sec'], $params['on_fail']);
        $steps->create([
            'scenario_id' => $scenarioId,
            'step_order' => $order,
            'action' => $type,
            'params' => $params,
            'timeout_sec' => $timeout,
            'on_fail' => $onFail,
        ]);
        $order++;
    }

    return $scenarioId;
}

/**
 * @param list<string> $args
 */
function scenarioCmdEvent(Connection $db, array $args): int
{
    $opts = scenarioParseArgs($args);
    $runId = isset($opts['run']) ? (int) $opts['run'] : 0;
    $event = isset($opts['event']) ? (string) $opts['event'] : '';
    if ($runId <= 0 || $event === '') {
        fwrite(STDERR, "Usage: scenario:event --run=ID --event=CODE [--level=info] [--message=...] [--data={json}]\n");

        return 1;
    }
    $level = isset($opts['level']) ? (string) $opts['level'] : 'info';
    $message = isset($opts['message']) ? (string) $opts['message'] : null;
    $data = null;
    if (isset($opts['data'])) {
        $decoded = json_decode((string) $opts['data'], true);
        $data = is_array($decoded) ? $decoded : ['raw' => $opts['data']];
    }

    $events = new RunsEventRepository($db);
    $id = $events->append($runId, $event, $level, $message, $data);
    echo "event_id={$id}\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function scenarioCmdImportEvents(Connection $db, array $args): int
{
    $opts = scenarioParseArgs($args);
    $runId = isset($opts['run']) ? (int) $opts['run'] : 0;
    $file = isset($opts['file']) ? (string) $opts['file'] : '';
    if ($runId <= 0 || $file === '' || !is_file($file)) {
        fwrite(STDERR, "Usage: scenario:import-events --run=ID --file=path.ndjson\n");

        return 1;
    }
    $events = new RunsEventRepository($db);
    $n = scenarioImportNdjson($events, $runId, $file);
    echo "imported={$n}\n";

    return 0;
}

function scenarioImportNdjson(RunsEventRepository $events, int $runId, string $file): int
{
    $existing = $events->findByTaskRun($runId);
    $seen = [];
    foreach ($existing as $row) {
        $key = ($row['event'] ?? '') . '|' . ($row['message'] ?? '') . '|' . ($row['created_at'] ?? '');
        $seen[$key] = true;
    }

    $n = 0;
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        return 0;
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row) || empty($row['event'])) {
            continue;
        }
        $event = (string) $row['event'];
        $level = (string) ($row['level'] ?? 'info');
        $message = isset($row['message']) ? (string) $row['message'] : null;
        $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : null;
        $ts = isset($row['ts']) ? (string) $row['ts'] : '';
        $key = $event . '|' . ($message ?? '') . '|' . $ts;
        if (isset($seen[$key])) {
            continue;
        }
        // Dedup loosely by event+message count already high — still append with data.ts
        if ($data === null) {
            $data = [];
        }
        if ($ts !== '') {
            $data['_ts'] = $ts;
        }
        $events->append($runId, $event, $level, $message, $data);
        $seen[$key] = true;
        $n++;
    }
    fclose($fh);

    return $n;
}

/**
 * @param list<string> $args
 * @return array<string, mixed>
 */
function scenarioParseArgs(array $args): array
{
    $out = ['_' => []];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            if ($eq === false) {
                $out[substr($arg, 2)] = true;
            } else {
                $out[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            }
        } else {
            $out['_'][] = $arg;
        }
    }

    return $out;
}

function scenarioCmdHelp(): int
{
    echo <<<'HELP'
Scenario CLI

  scenario:run --account=ID --scenario=warmup|pf|service [--day=N] [--fast]
  scenario:sync [--scenario=NAME]     Upsert JSON → scenarios + scenario_steps
  scenario:event --run=ID --event=CODE [--level=info] [--message=...] [--data={json}]
  scenario:import-events --run=ID --file=path.ndjson

HELP;

    return 0;
}
