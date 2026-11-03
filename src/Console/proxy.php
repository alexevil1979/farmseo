#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Proxy pool CLI: proxy:import | proxy:check | proxy:rotate | proxy:stats
 *
 * Usage:
 *   php src/Console/proxy.php import path/to/file.csv [--protocol=http] [--country=RU]
 *   php src/Console/proxy.php check [--id=1] [--all]
 *   php src/Console/proxy.php rotate
 *   php src/Console/proxy.php stats
 *
 * Also available via: php farm proxy:import|proxy:check|proxy:rotate|proxy:stats
 */

use Farm\Db\Connection;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
// When included from farm, $argv already shifted; when run directly, drop script name.
if (isset($argv[0]) && (str_ends_with($argv[0], 'proxy.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

// Support both "import" and "proxy:import"
$raw = $argv[0] ?? 'help';
$command = str_starts_with($raw, 'proxy:') ? substr($raw, 6) : $raw;
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'import' => proxyCmdImport($factory, $args),
    'check' => proxyCmdCheck($factory, $args),
    'rotate' => proxyCmdRotate($factory, $args),
    'stats' => proxyCmdStats($factory),
    'help', '--help', '-h' => proxyCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown proxy command: {$command}\n");
        proxyCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_PROXY_AS_LIBRARY']) && $_SERVER['FARM_PROXY_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function proxyCmdImport(ServiceFactory $factory, array $args): int
{
    $file = null;
    $protocol = 'http';
    $country = null;

    foreach ($args as $arg) {
        if (preg_match('/^--protocol=(.+)$/', $arg, $m)) {
            $protocol = strtolower($m[1]);
        } elseif (preg_match('/^--country=([A-Za-z]{2})$/', $arg, $m)) {
            $country = strtoupper($m[1]);
        } elseif (!str_starts_with($arg, '--')) {
            $file = $arg;
        }
    }

    if ($file === null) {
        fwrite(STDERR, "Usage: proxy:import <file.csv> [--protocol=http] [--country=RU]\n");
        fwrite(STDERR, "CSV lines: host:port:user:pass\n");

        return 1;
    }

    if (!is_readable($file)) {
        fwrite(STDERR, "File not readable: {$file}\n");

        return 1;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Failed to read: {$file}\n");

        return 1;
    }

    // importLines does not acquire Redis locks (lazy connect)
    $result = $factory->proxyPool()->importLines($lines, $protocol, $country);

    echo "Import done\n";
    echo "  imported: {$result['imported']}\n";
    echo "  skipped:  {$result['skipped']}\n";
    if ($result['errors'] !== []) {
        echo '  errors:   ' . count($result['errors']) . "\n";
        foreach (array_slice($result['errors'], 0, 20) as $err) {
            echo "    - {$err}\n";
        }
        if (count($result['errors']) > 20) {
            echo '    ... and ' . (count($result['errors']) - 20) . " more\n";
        }

        return 1;
    }

    return 0;
}

/**
 * @param list<string> $args
 */
function proxyCmdCheck(ServiceFactory $factory, array $args): int
{
    $ids = [];
    $all = false;

    foreach ($args as $arg) {
        if ($arg === '--all') {
            $all = true;
        } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
            $ids[] = (int) $m[1];
        } elseif (preg_match('/^\d+$/', $arg)) {
            $ids[] = (int) $arg;
        }
    }

    $checker = $factory->proxyChecker();

    if (!$all && $ids === []) {
        // Default: check all active
        $all = true;
    }

    $results = $all ? $checker->checkMany(null) : $checker->checkMany($ids);

    $counts = ['ok' => 0, 'slow' => 0, 'dead' => 0, 'unknown' => 0];
    foreach ($results as $r) {
        $h = (string) $r['health'];
        $counts[$h] = ($counts[$h] ?? 0) + 1;
        $lat = $r['latency_ms'] !== null ? $r['latency_ms'] . 'ms' : '-';
        $ip = $r['ip'] ?? '-';
        $cc = $r['country'] ?? '-';
        $type = $r['type'] ?? '-';
        $err = $r['error'] !== null ? ' err=' . $r['error'] : '';
        echo sprintf(
            "#%d %s latency=%s ip=%s country=%s type=%s asn=%s%s\n",
            $r['proxy_id'],
            $r['health'],
            $lat,
            $ip,
            $cc,
            $type,
            $r['asn'] ?? '-',
            $err
        );
    }

    echo sprintf(
        "Checked %d: ok=%d slow=%d dead=%d\n",
        count($results),
        $counts['ok'],
        $counts['slow'],
        $counts['dead']
    );

    return 0;
}

/**
 * @param list<string> $args
 */
function proxyCmdRotate(ServiceFactory $factory, array $args): int
{
    unset($args);
    try {
        $pool = $factory->proxyPool();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Redis required for rotate: ' . $e->getMessage() . "\n");

        return 1;
    }

    $results = $pool->rotateDead();
    if ($results === []) {
        echo "No dead proxy assignments to rotate.\n";

        return 0;
    }

    $ok = 0;
    $fail = 0;
    foreach ($results as $r) {
        if ($r['ok']) {
            $ok++;
            echo sprintf(
                "account #%d: proxy #%d -> #%d\n",
                $r['account_id'],
                $r['old_proxy_id'],
                $r['new_proxy_id']
            );
        } else {
            $fail++;
            echo sprintf(
                "account #%d: FAILED (%s) old=#%d\n",
                $r['account_id'],
                $r['error'] ?? 'unknown',
                $r['old_proxy_id']
            );
        }
    }

    echo "Rotated ok={$ok} failed={$fail}\n";

    return $fail > 0 ? 2 : 0;
}

function proxyCmdStats(ServiceFactory $factory): int
{
    $stats = $factory->proxyRepository()->poolStats();
    echo "Proxy pool stats\n";
    echo "  total:    {$stats['total']}\n";
    echo "  active:   {$stats['active']}\n";
    echo "  assigned: {$stats['assigned']}\n";
    echo "  free:     {$stats['free']}\n";
    echo "  health:\n";
    foreach ($stats['by_health'] as $h => $c) {
        echo "    {$h}: {$c}\n";
    }
    if ($stats['by_country'] !== []) {
        echo "  countries:\n";
        foreach ($stats['by_country'] as $cc => $c) {
            echo "    {$cc}: {$c}\n";
        }
    }

    return 0;
}

function proxyCmdHelp(): int
{
    echo <<<'HELP'
Proxy pool CLI

Commands:
  proxy:import <file> [--protocol=http] [--country=RU]
      Import proxies from CSV/TXT lines: host:port:user:pass

  proxy:check [--all] [--id=N]
      Probe proxies via ip-api/ipinfo; set health ok|slow|dead

  proxy:rotate
      Replace dead assigned proxies with live ones from same geo

  proxy:stats
      Show pool totals, health and country breakdown

HELP;

    return 0;
}
