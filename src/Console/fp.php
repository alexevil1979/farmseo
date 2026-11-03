#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fingerprint CLI: fp:generate | fp:assign | fp:validate
 *
 * Usage:
 *   php src/Console/fp.php generate --account=1
 *   php src/Console/fp.php assign --group=2
 *   php src/Console/fp.php validate --all
 *
 * Also: php farm fp:generate|fp:assign|fp:validate
 */

use Farm\Db\Connection;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'fp.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = str_starts_with($raw, 'fp:') ? substr($raw, 3) : $raw;
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'generate' => fpCmdGenerate($factory, $args),
    'assign' => fpCmdAssign($factory, $args),
    'validate' => fpCmdValidate($factory, $args),
    'help', '--help', '-h' => fpCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown fp command: {$command}\n");
        fpCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_FP_AS_LIBRARY']) && $_SERVER['FARM_FP_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function fpCmdGenerate(ServiceFactory $factory, array $args): int
{
    $accountId = null;
    $device = null;
    $force = false;

    foreach ($args as $arg) {
        if (preg_match('/^--account=(\d+)$/', $arg, $m)) {
            $accountId = (int) $m[1];
        } elseif (preg_match('/^--device=(desktop|mobile)$/', $arg, $m)) {
            $device = $m[1];
        } elseif ($arg === '--force') {
            $force = true;
        }
    }

    if ($accountId === null) {
        fwrite(STDERR, "Usage: fp:generate --account=ID [--device=desktop|mobile] [--force]\n");

        return 1;
    }

    $accounts = $factory->accountRepository();
    $account = $accounts->findById($accountId);
    if ($account === null) {
        fwrite(STDERR, "Account #{$accountId} not found\n");

        return 1;
    }

    $fps = $factory->accountFingerprintRepository();
    $existing = $fps->findByAccountId($accountId);
    if ($existing !== null && !$force) {
        fwrite(STDERR, "Account #{$accountId} already has a fingerprint (use --force to replace)\n");

        return 1;
    }

    $proxy = null;
    if (!empty($account['proxy_id'])) {
        $proxy = $factory->proxyRepository()->findById((int) $account['proxy_id']);
    }

    $opts = fpBuildOpts($proxy, $device);
    $generator = $factory->fingerprintGenerator();
    $fp = $generator->generate($opts);

    $validator = $factory->fingerprintValidator();
    $check = $validator->validate($fp, $proxy);
    if (!$check['ok']) {
        fwrite(STDERR, "Generated fp failed validation:\n");
        foreach ($check['errors'] as $err) {
            fwrite(STDERR, "  - {$err}\n");
        }

        return 2;
    }

    $extraMerge = [];
    if ($proxy !== null) {
        $mapped = $factory->geoMapper()->forAccount($proxy);
        $extraMerge = $mapped['extra'];
    }

    $row = $generator->toRow($accountId, $fp, $extraMerge);
    $id = $fps->upsertForAccount($accountId, $row);

    echo "Generated fingerprint for account #{$accountId} (row #{$id})\n";
    echo "  hash:     {$fp['hash']}\n";
    echo "  profile:  {$fp['profile']} / {$fp['os']}\n";
    echo "  platform: {$fp['platform']}\n";
    echo "  tz:       {$fp['timezone']}\n";
    echo "  lang:     {$fp['lang']}\n";
    echo "  screen:   {$fp['screen']['width']}x{$fp['screen']['height']} @{$fp['dpr']}\n";

    return 0;
}

/**
 * Assign fingerprints to every account in a group (skip those that already have one unless --force).
 *
 * @param list<string> $args
 */
function fpCmdAssign(ServiceFactory $factory, array $args): int
{
    $groupId = null;
    $force = false;
    $device = null;

    foreach ($args as $arg) {
        if (preg_match('/^--group=(\d+)$/', $arg, $m)) {
            $groupId = (int) $m[1];
        } elseif (preg_match('/^--device=(desktop|mobile)$/', $arg, $m)) {
            $device = $m[1];
        } elseif ($arg === '--force') {
            $force = true;
        }
    }

    if ($groupId === null) {
        fwrite(STDERR, "Usage: fp:assign --group=ID [--device=desktop|mobile] [--force]\n");

        return 1;
    }

    $accounts = $factory->accountRepository()->findByGroupId($groupId);
    if ($accounts === []) {
        echo "No accounts in group #{$groupId}\n";

        return 0;
    }

    $generator = $factory->fingerprintGenerator();
    $validator = $factory->fingerprintValidator();
    $fps = $factory->accountFingerprintRepository();
    $proxies = $factory->proxyRepository();

    $created = 0;
    $skipped = 0;
    $failed = 0;
    $hashes = [];

    foreach ($accounts as $account) {
        $accountId = (int) $account['id'];
        $existing = $fps->findByAccountId($accountId);
        if ($existing !== null && !$force) {
            $skipped++;
            continue;
        }

        $proxy = !empty($account['proxy_id'])
            ? $proxies->findById((int) $account['proxy_id'])
            : null;

        $fp = $generator->generate(fpBuildOpts($proxy, $device));
        $check = $validator->validate($fp, $proxy);
        if (!$check['ok']) {
            $failed++;
            fwrite(STDERR, "account #{$accountId}: validation failed: " . implode(', ', $check['errors']) . "\n");
            continue;
        }

        if (isset($hashes[$fp['hash']])) {
            // Extremely unlikely; regenerate once
            $fp = $generator->generate(fpBuildOpts($proxy, $device, bin2hex(random_bytes(8))));
        }
        $hashes[$fp['hash']] = $accountId;

        $extraMerge = $proxy !== null ? $factory->geoMapper()->forAccount($proxy)['extra'] : [];
        $fps->upsertForAccount($accountId, $generator->toRow($accountId, $fp, $extraMerge));
        $created++;
        echo sprintf(
            "account #%d: %s/%s tz=%s hash=%s\n",
            $accountId,
            $fp['profile'],
            $fp['os'],
            $fp['timezone'],
            substr((string) $fp['hash'], 0, 12)
        );
    }

    echo "Assign done: created={$created} skipped={$skipped} failed={$failed}\n";

    return $failed > 0 ? 2 : 0;
}

/**
 * @param list<string> $args
 */
function fpCmdValidate(ServiceFactory $factory, array $args): int
{
    $all = false;
    $accountId = null;

    foreach ($args as $arg) {
        if ($arg === '--all') {
            $all = true;
        } elseif (preg_match('/^--account=(\d+)$/', $arg, $m)) {
            $accountId = (int) $m[1];
        }
    }

    if (!$all && $accountId === null) {
        fwrite(STDERR, "Usage: fp:validate --all | --account=ID\n");

        return 1;
    }

    $fps = $factory->accountFingerprintRepository();
    $accounts = $factory->accountRepository();
    $proxies = $factory->proxyRepository();
    $generator = $factory->fingerprintGenerator();
    $validator = $factory->fingerprintValidator();

    $rows = $all
        ? $fps->getAll([], orderBy: 'account_id ASC')
        : (static function () use ($fps, $accountId): array {
            $row = $fps->findByAccountId((int) $accountId);

            return $row !== null ? [$row] : [];
        })();

    if ($rows === []) {
        echo "No fingerprints to validate.\n";

        return 0;
    }

    $ok = 0;
    $bad = 0;

    foreach ($rows as $row) {
        $accId = (int) $row['account_id'];
        $fp = $generator->fromRow($row);
        if ($fp === null) {
            $bad++;
            echo "account #{$accId}: FAIL missing fp in extra\n";
            continue;
        }

        $account = $accounts->findById($accId);
        $proxy = ($account !== null && !empty($account['proxy_id']))
            ? $proxies->findById((int) $account['proxy_id'])
            : null;

        // Also enforce denormalized columns match fp
        $columnErrors = [];
        if (($row['user_agent'] ?? null) !== ($fp['ua'] ?? null)) {
            $columnErrors[] = 'user_agent_column_mismatch';
        }
        if (($row['timezone'] ?? null) !== ($fp['timezone'] ?? null)) {
            $columnErrors[] = 'timezone_column_mismatch';
        }
        if (($row['locale'] ?? null) !== ($fp['lang'] ?? null)) {
            $columnErrors[] = 'locale_column_mismatch';
        }

        $check = $validator->validate($fp, $proxy);
        $errors = array_merge($check['errors'], $columnErrors);

        if ($errors === []) {
            $ok++;
            echo "account #{$accId}: OK\n";
        } else {
            $bad++;
            echo "account #{$accId}: FAIL " . implode('; ', $errors) . "\n";
        }
    }

    echo "Validated " . count($rows) . ": ok={$ok} fail={$bad}\n";

    return $bad > 0 ? 2 : 0;
}

/**
 * @param array<string, mixed>|null $proxy
 * @return array{country?: string|null, city?: string|null, timezone?: string|null, device?: string|null, seed?: string}
 */
function fpBuildOpts(?array $proxy, ?string $device, ?string $seed = null): array
{
    $opts = [];
    if ($device !== null) {
        $opts['device'] = $device;
    }
    if ($seed !== null) {
        $opts['seed'] = $seed;
    }
    if ($proxy === null) {
        return $opts;
    }

    $meta = $proxy['meta'] ?? null;
    if (is_string($meta)) {
        $meta = json_decode($meta, true) ?: [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }

    $opts['country'] = $proxy['country'] ?? ($meta['country'] ?? null);
    $opts['city'] = $meta['city'] ?? null;
    $opts['timezone'] = $meta['timezone'] ?? ($meta['tz'] ?? null);

    return $opts;
}

function fpCmdHelp(): int
{
    echo <<<'HELP'
Fingerprint CLI

Commands:
  fp:generate --account=ID [--device=desktop|mobile] [--force]
      Generate a coherent fingerprint for one account (tz/lang from proxy geo)

  fp:assign --group=ID [--device=desktop|mobile] [--force]
      Generate & store fingerprints for all accounts in a group

  fp:validate --all | --account=ID
      Check consistency (ua↔platform↔screen↔tz↔proxy country)

HELP;

    return 0;
}
