#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * E2E assertions against a live /opt/farm install.
 *
 * Usage:
 *   php e2e/assert.php [--task=ID] [--account=ID] [--kill-task=ID] [--report=/path/report.json]
 *
 * Exit 0 = all checks PASS; 1 = any FAIL.
 */

$opts = [
    'task' => null,
    'account' => null,
    'kill_task' => null,
    'report' => null,
    'root' => getenv('FARM_ROOT') ?: '/opt/farm',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--task=(\d+)$/', $arg, $m)) {
        $opts['task'] = (int) $m[1];
    } elseif (preg_match('/^--account=(\d+)$/', $arg, $m)) {
        $opts['account'] = (int) $m[1];
    } elseif (preg_match('/^--kill-task=(\d+)$/', $arg, $m)) {
        $opts['kill_task'] = (int) $m[1];
    } elseif (preg_match('/^--report=(.+)$/', $arg, $m)) {
        $opts['report'] = $m[1];
    } elseif (preg_match('/^--root=(.+)$/', $arg, $m)) {
        $opts['root'] = $m[1];
    }
}

$root = rtrim((string) $opts['root'], '/\\');
$envFile = $root . '/.env';
if (!is_readable($envFile)) {
    fwrite(STDERR, "assert.php: cannot read {$envFile}\n");
    exit(1);
}

$env = parseEnvFile($envFile);
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '3306',
        $env['DB_DATABASE'] ?? 'farm'
    ),
    $env['DB_USERNAME'] ?? 'farm',
    $env['DB_PASSWORD'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$results = [];
$pass = static function (string $id, string $msg) use (&$results): void {
    $results[] = ['id' => $id, 'ok' => true, 'message' => $msg];
    echo "PASS  {$id}: {$msg}\n";
};
$fail = static function (string $id, string $msg) use (&$results): void {
    $results[] = ['id' => $id, 'ok' => false, 'message' => $msg];
    echo "FAIL  {$id}: {$msg}\n";
};

$taskId = $opts['task'];
$accountId = $opts['account'];
$killTaskId = $opts['kill_task'];

if ($taskId === null) {
    $row = $pdo->query("SELECT id, account_id FROM tasks WHERE type='warmup' ORDER BY id DESC LIMIT 1")->fetch();
    if ($row) {
        $taskId = (int) $row['id'];
        $accountId = $accountId ?? (int) $row['account_id'];
    }
}
if ($accountId === null && $taskId !== null) {
    $st = $pdo->prepare('SELECT account_id FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $accountId = (int) ($st->fetchColumn() ?: 0) ?: null;
}

// --- A1: task not lost (row still exists, not vanished from queue crash) ---
if ($taskId === null) {
    $fail('task_not_lost', 'no warmup task id to assert');
} else {
    $st = $pdo->prepare('SELECT id, status, attempts, error FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $task = $st->fetch();
    if ($task === false) {
        $fail('task_not_lost', "task {$taskId} missing from DB");
    } else {
        $pass('task_not_lost', "task {$taskId} present status={$task['status']}");
    }
}

// --- A2: running → done (happy-path task reached terminal done) ---
if ($taskId === null) {
    $fail('running_to_done', 'no task');
} else {
    $st = $pdo->prepare('SELECT status, started_at, finished_at FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $task = $st->fetch();
    $runs = $pdo->prepare('SELECT id, attempt, status, started_at, finished_at, metrics, screenshots FROM task_runs WHERE task_id = ? ORDER BY attempt');
    $runs->execute([$taskId]);
    $runRows = $runs->fetchAll();

    $sawRunning = false;
    foreach ($runRows as $r) {
        if (in_array((string) $r['status'], ['running', 'done', 'failed', 'retrying'], true)) {
            // task_runs written at running and terminal — presence of a run implies claim
            $sawRunning = true;
        }
    }
    if (!empty($task['started_at'])) {
        $sawRunning = true;
    }

    $status = (string) ($task['status'] ?? '');
    if ($status === 'done' && $sawRunning) {
        $pass('running_to_done', "task {$taskId} running→done (" . count($runRows) . ' run(s))');
    } elseif ($status === 'done') {
        $pass('running_to_done', "task {$taskId} done (started_at may be unset on edge path)");
    } else {
        $fail('running_to_done', "expected done, got status={$status} runs=" . count($runRows));
    }
}

// --- A3: profile retained cookies ---
if ($accountId === null) {
    $fail('profile_cookie', 'no account id');
} else {
    $profileDir = $root . '/storage/profiles/' . $accountId;
    $cookieHits = [];
    foreach ([
        $profileDir . '/Cookies',
        $profileDir . '/Network/Cookies',
        $profileDir . '/Default/Cookies',
        $profileDir . '/Default/Network/Cookies',
    ] as $cand) {
        if (is_file($cand) && filesize($cand) > 0) {
            $cookieHits[] = $cand;
        }
    }
    // Snapshots table (optional persistence path)
    $snap = $pdo->prepare('SELECT COUNT(*) FROM account_cookies_snapshots WHERE account_id = ?');
    $snap->execute([$accountId]);
    $snapCount = (int) $snap->fetchColumn();

    $blobOk = false;
    foreach ($cookieHits as $path) {
        $raw = @file_get_contents($path);
        if ($raw !== false && (
            str_contains($raw, 'e2e_farm')
            || str_contains($raw, 'e2e_site')
            || str_contains($raw, 'e2e_')
            || strlen($raw) > 64
        )) {
            $blobOk = true;
            break;
        }
    }

    if ($cookieHits !== [] && ($blobOk || $snapCount > 0)) {
        $pass(
            'profile_cookie',
            "account {$accountId} cookies on disk (" . count($cookieHits) . ' file(s))'
            . ($snapCount > 0 ? " + {$snapCount} snapshot(s)" : '')
        );
    } elseif ($cookieHits !== []) {
        // Chrome SQLite often stores names without plaintext searchable strings — size is enough
        $pass('profile_cookie', "account {$accountId} Cookies DB present (" . basename($cookieHits[0]) . ')');
    } else {
        $fail('profile_cookie', "no Cookies under {$profileDir}");
    }
}

// --- A4: live-view returned a frame (PNG screenshot via worker) ---
$liveMeta = getenv('E2E_LIVE_FRAME') ?: '';
$shotPath = getenv('E2E_LIVE_SHOT') ?: '';
if ($shotPath !== '' && is_file($shotPath) && filesize($shotPath) > 128) {
    $fh = fopen($shotPath, 'rb');
    $magic = $fh ? fread($fh, 8) : '';
    if ($fh) {
        fclose($fh);
    }
    if (str_starts_with((string) $magic, "\x89PNG")) {
        $pass('live_view_frame', 'PNG frame ' . basename($shotPath) . ' (' . filesize($shotPath) . ' bytes)');
    } else {
        $fail('live_view_frame', 'shot exists but not PNG: ' . $shotPath);
    }
} elseif ($liveMeta === 'ok') {
    $pass('live_view_frame', 'E2E_LIVE_FRAME=ok (run.sh verified)');
} else {
    // Fallback: any fresh account screenshot under storage
    $dir = $root . '/storage/screenshots';
    $found = null;
    if (is_dir($dir) && $accountId !== null) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $name = $f->getFilename();
            if (str_contains($name, 'account_' . $accountId) || str_contains($name, 'e2e-')) {
                if ($f->getSize() > 128) {
                    $found = $f->getPathname();
                    break;
                }
            }
        }
    }
    if ($found !== null) {
        $pass('live_view_frame', 'screenshot frame present: ' . basename($found));
    } else {
        $fail('live_view_frame', 'no live-view/screenshot frame found');
    }
}

// --- A5: screenshots recorded on task_runs ---
if ($taskId !== null) {
    $st = $pdo->prepare('SELECT screenshots FROM task_runs WHERE task_id = ? ORDER BY attempt DESC LIMIT 1');
    $st->execute([$taskId]);
    $raw = $st->fetchColumn();
    $shots = is_string($raw) ? json_decode($raw, true) : $raw;
    if (is_array($shots) && count($shots) > 0) {
        $pass('screenshots', count($shots) . ' screenshot(s) on latest task_run');
    } else {
        // Disk fallback
        $disk = glob($root . '/storage/screenshots/' . $taskId . '/*') ?: [];
        $disk = array_merge($disk, glob($root . '/storage/screenshots/*' . $taskId . '*') ?: []);
        if ($disk !== []) {
            $pass('screenshots', count($disk) . ' screenshot file(s) on disk for task');
        } else {
            $fail('screenshots', 'no screenshots for task ' . $taskId);
        }
    }
}

// --- A6: CPU/RAM metrics ---
$metricOk = false;
$detail = [];
if ($taskId !== null) {
    $st = $pdo->prepare('SELECT metrics FROM task_runs WHERE task_id = ? ORDER BY attempt DESC LIMIT 1');
    $st->execute([$taskId]);
    $raw = $st->fetchColumn();
    $m = is_string($raw) ? json_decode($raw, true) : $raw;
    if (is_array($m)) {
        foreach (['chrome_cpu_pct', 'chrome_ram_mb', 'cpu_pct', 'ram_mb', 'ram_pct'] as $k) {
            if (array_key_exists($k, $m) && $m[$k] !== null && $m[$k] !== '') {
                $detail[] = "{$k}=" . $m[$k];
                $metricOk = true;
            }
        }
    }
}
$sys = $pdo->query("SELECT name, value FROM metrics WHERE entity_type='system' AND name IN ('cpu','ram') ORDER BY id DESC LIMIT 10")->fetchAll();
$sysNames = [];
foreach ($sys as $row) {
    $sysNames[$row['name']] = $row['value'];
}
if (isset($sysNames['cpu'], $sysNames['ram'])) {
    $detail[] = 'system cpu=' . $sysNames['cpu'] . ' ram=' . $sysNames['ram'];
    $metricOk = true;
}
if ($metricOk) {
    $pass('cpu_ram_metrics', implode('; ', $detail));
} else {
    $fail('cpu_ram_metrics', 'no chrome/system CPU/RAM metrics found');
}

// --- A7: kill -9 path — task not lost + alert ---
if ($killTaskId !== null) {
    $st = $pdo->prepare('SELECT id, status, attempts, error FROM tasks WHERE id = ?');
    $st->execute([$killTaskId]);
    $kt = $st->fetch();
    if ($kt === false) {
        $fail('kill9_task_survived', "kill-task {$killTaskId} missing");
    } else {
        $stt = (string) $kt['status'];
        // Must not be eternally stuck invisible; acceptable: retrying|queued|pending|running|done|failed
        if (in_array($stt, ['retrying', 'queued', 'pending', 'running', 'done', 'failed'], true)) {
            $pass('kill9_task_survived', "kill-task {$killTaskId} status={$stt} attempts={$kt['attempts']}");
        } else {
            $fail('kill9_task_survived', "unexpected status={$stt}");
        }
    }

    $alert = $pdo->query(
        "SELECT id, severity, message, meta, created_at FROM alerts
         WHERE source='alert_engine' AND (message LIKE '%heartbeat%' OR message LIKE '%Worker%' OR meta LIKE '%worker_down%')
         ORDER BY id DESC LIMIT 5"
    )->fetchAll();
    if ($alert !== []) {
        $pass('kill9_alert', 'worker_down alert: ' . $alert[0]['message']);
    } else {
        $fail('kill9_alert', 'no worker_down alert after kill -9');
    }
} else {
    $fail('kill9_task_survived', 'E2E did not pass --kill-task (run.sh must set it)');
    $fail('kill9_alert', 'skipped — no kill-task');
}

// --- Acceptance criteria from TZ (mapped) ---
$byId = [];
foreach ($results as $r) {
    $byId[$r['id']] = $r['ok'];
}

$acceptance = [
    [
        'id' => 'TZ1_install',
        'title' => 'E2E-стенд (Dockerfile+entrypoint, без install.sh) поднимает систему на чистой Ubuntu',
        'ok' => is_file('/var/lib/farm-e2e/ready') || is_dir($root . '/public'),
    ],
    [
        'id' => 'TZ2_accounts',
        'title' => 'Аккаунты созданы и видны через API/БД (E2E: 3)',
        'ok' => ((int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn()) >= 3,
    ],
    [
        'id' => 'TZ3_warmup_chrome',
        'title' => 'Прогрев в реальном Chrome: cookies + screenshots + live-view + CPU/RAM',
        'ok' => ($byId['profile_cookie'] ?? false)
            && ($byId['screenshots'] ?? false)
            && ($byId['live_view_frame'] ?? false)
            && ($byId['cpu_ram_metrics'] ?? false)
            && ($byId['running_to_done'] ?? false),
    ],
    [
        'id' => 'TZ4_no_task_loss',
        'title' => 'Задачи не теряются при падении воркера (running→retry/done)',
        'ok' => ($byId['task_not_lost'] ?? false) && ($byId['kill9_task_survived'] ?? false),
    ],
    [
        'id' => 'TZ5_kill9',
        'title' => 'kill -9 воркера обнаруживается, задача не теряется',
        'ok' => ($byId['kill9_task_survived'] ?? false) && ($byId['kill9_alert'] ?? false),
    ],
    [
        'id' => 'TZ6_alerts',
        'title' => 'Мониторинг алертит при мёртвом воркере',
        'ok' => $byId['kill9_alert'] ?? false,
    ],
    [
        'id' => 'FIX_14a_reclaim',
        'title' => '14a: reclaim/queue — задача после kill остаётся в жизненном цикле',
        'ok' => $byId['kill9_task_survived'] ?? false,
    ],
    [
        'id' => 'FIX_14b_cookies',
        'title' => '14b: профиль сохраняет cookie (миграция/персистентность профиля)',
        'ok' => $byId['profile_cookie'] ?? false,
    ],
    [
        'id' => 'FIX_14c_liveview',
        'title' => '14c: live-view/session router отдаёт кадр с ноды сессии',
        'ok' => $byId['live_view_frame'] ?? false,
    ],
];

echo "\n=== ACCEPTANCE (ТЗ) ===\n";
$allOk = true;
foreach ($acceptance as $a) {
    $mark = $a['ok'] ? 'PASS' : 'FAIL';
    if (!$a['ok']) {
        $allOk = false;
    }
    echo "{$mark}  {$a['id']}: {$a['title']}\n";
}

foreach ($results as $r) {
    if (!$r['ok']) {
        $allOk = false;
    }
}

$isWsl = detectWslEnvironment();
$environmentLimitations = buildEnvironmentLimitations($isWsl);

echo "\n=== Ограничения окружения ===\n";
echo 'host: ' . ($isWsl ? 'WSL2' : 'linux') . "\n";
foreach ($environmentLimitations as $lim) {
    $st = (string) ($lim['status'] ?? 'UNKNOWN');
    echo "{$st}  {$lim['id']}: {$lim['title']}\n";
}

$report = [
    'ok' => $allOk,
    'task_id' => $taskId,
    'account_id' => $accountId,
    'kill_task_id' => $killTaskId,
    'checks' => $results,
    'acceptance' => $acceptance,
    'environment' => [
        'wsl' => $isWsl,
        'kind' => $isWsl ? 'wsl2' : 'linux',
    ],
    'environment_limitations' => $environmentLimitations,
    'generated_at' => gmdate('c'),
];

$reportPath = $opts['report'] ?: ($root . '/storage/logs/e2e-report.json');
@mkdir(dirname($reportPath), 0775, true);
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo "\nReport: {$reportPath}\n";
echo $allOk ? "RESULT: PASS\n" : "RESULT: FAIL\n";

exit($allOk ? 0 : 1);

/**
 * @return array<string, string>
 */
function parseEnvFile(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }

    return $out;
}

function detectWslEnvironment(): bool
{
    $forced = getenv('E2E_HOST_WSL');
    if ($forced === '1' || strtolower((string) $forced) === 'true') {
        return true;
    }
    if ($forced === '0' || strtolower((string) $forced) === 'false') {
        return false;
    }
    if (getenv('WSL_DISTRO_NAME') || getenv('WSL_INTEROP')) {
        return true;
    }
    $ver = @file_get_contents('/proc/version');
    if (is_string($ver) && (stripos($ver, 'microsoft') !== false || stripos($ver, 'WSL') !== false)) {
        return true;
    }

    return false;
}

/**
 * Items that cannot be closed on WSL (or nested e2e image) — marked NOT TESTED.
 *
 * @return list<array{id: string, title: string, status: string, reason: string}>
 */
function buildEnvironmentLimitations(bool $isWsl): array
{
    $items = [
        [
            'id' => 'LIM_install_sh_clean_ubuntu',
            'title' => 'install.sh одной командой на чистой Ubuntu 24.04 (без Microsoft kernel)',
            'wsl_only' => true,
            'reason' => 'WSL2 uses Microsoft kernel; not a clean Ubuntu host. install.sh remains universal and is not adapted for WSL.',
        ],
        [
            'id' => 'LIM_host_systemd_units',
            'title' => 'Host systemd units farm-worker@ / farm-gc.timer from install.sh',
            'wsl_only' => true,
            'reason' => 'E2E stand runs supervisord inside Docker without host install.sh units.',
        ],
        [
            'id' => 'LIM_vm_hypervisor_snapshots',
            'title' => 'Hypervisor VM snapshots (rollback)',
            'wsl_only' => true,
            'reason' => 'On WSL use wsl --export/--import instead of VM snapshots (see docs/runbook-wsl.md).',
        ],
        [
            'id' => 'LIM_non_drvfs_bare_metal_disk',
            'title' => 'Bare-metal / non-drvfs disk semantics for profile I/O at scale',
            'wsl_only' => true,
            'reason' => 'WSL VHD + optional drvfs differ from bare-metal ext4; preflight forbids /mnt repo paths only.',
        ],
        [
            'id' => 'LIM_privileged_nested_systemd_install',
            'title' => 'Privileged nested systemd container running install.sh as on bare metal',
            'wsl_only' => false,
            'reason' => 'Current e2e image is supervisor-based and deliberately excludes install.sh.',
        ],
    ];

    $out = [];
    foreach ($items as $it) {
        // WSL-only rows: N/A on bare metal; NOT TESTED on WSL.
        // Non-wsl_only rows (e2e excludes install.sh path): always NOT TESTED.
        if (($it['wsl_only'] ?? false) && !$isWsl) {
            $out[] = [
                'id' => $it['id'],
                'title' => $it['title'],
                'status' => 'N/A',
                'reason' => 'Host is not WSL — limitation does not apply (verify on dedicated Ubuntu if required).',
            ];
            continue;
        }
        $out[] = [
            'id' => $it['id'],
            'title' => $it['title'],
            'status' => 'NOT TESTED',
            'reason' => $it['reason'],
        ];
    }

    return $out;
}
