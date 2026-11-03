#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * API token CLI: issue | revoke | list
 *
 * Usage:
 *   php src/Console/token.php issue --user=1 --name=ci [--expires=30d]
 *   php src/Console/token.php revoke --id=3
 *   php src/Console/token.php revoke --token=PLAINTEXT
 *   php src/Console/token.php list --user=1
 *
 * Also: php farm token:issue|token:revoke|token:list
 */

use Farm\Db\Connection;
use Farm\Repositories\ApiTokenRepository;
use Farm\Repositories\UserRepository;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'token.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = str_starts_with($raw, 'token:') ? substr($raw, 6) : $raw;
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'issue' => tokenCmdIssue($factory, $args),
    'revoke' => tokenCmdRevoke($factory, $args),
    'list' => tokenCmdList($factory, $args),
    'help', '--help', '-h' => tokenCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown token command: {$command}\n");
        tokenCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_TOKEN_AS_LIBRARY']) && $_SERVER['FARM_TOKEN_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function tokenCmdIssue(ServiceFactory $factory, array $args): int
{
    $userId = null;
    $email = null;
    $name = 'cli';
    $expires = null;

    foreach ($args as $arg) {
        if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
            $userId = (int) $m[1];
        } elseif (preg_match('/^--email=(.+)$/', $arg, $m)) {
            $email = trim($m[1]);
        } elseif (preg_match('/^--name=(.+)$/', $arg, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/^--expires=(.+)$/', $arg, $m)) {
            $expires = trim($m[1]);
        }
    }

    $users = new UserRepository($factory->db());
    if ($userId === null && $email !== null) {
        $row = $users->findByEmail(strtolower($email));
        if ($row === null) {
            fwrite(STDERR, "User not found: {$email}\n");

            return 1;
        }
        $userId = (int) $row['id'];
    }
    if ($userId === null) {
        fwrite(STDERR, "Usage: token:issue --user=ID|--email=ADDR --name=LABEL [--expires=30d]\n");

        return 1;
    }

    $user = $users->findById($userId);
    if ($user === null) {
        fwrite(STDERR, "User #{$userId} not found\n");

        return 1;
    }

    $expiresAt = null;
    if ($expires !== null && $expires !== '') {
        $expiresAt = tokenParseExpires($expires);
        if ($expiresAt === null) {
            fwrite(STDERR, "Invalid --expires (use 30d, 12h, or ISO datetime)\n");

            return 1;
        }
    }

    $plain = $factory->auth()->createApiToken($userId, $name, null, $expiresAt);
    echo "token_id: issued\n";
    echo "user_id: {$userId}\n";
    echo "name: {$name}\n";
    echo "token: {$plain}\n";
    echo "(store this token now — it will not be shown again)\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function tokenCmdRevoke(ServiceFactory $factory, array $args): int
{
    $id = null;
    $plain = null;
    foreach ($args as $arg) {
        if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
            $id = (int) $m[1];
        } elseif (preg_match('/^--token=(.+)$/', $arg, $m)) {
            $plain = trim($m[1]);
        }
    }

    if ($id === null && $plain === null) {
        fwrite(STDERR, "Usage: token:revoke --id=ID | --token=PLAIN\n");

        return 1;
    }

    $ok = $id !== null
        ? $factory->auth()->revokeApiToken($id)
        : $factory->auth()->revokeApiTokenByPlain((string) $plain);

    if (!$ok) {
        fwrite(STDERR, "Token not found or already revoked\n");

        return 1;
    }

    echo "revoked\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function tokenCmdList(ServiceFactory $factory, array $args): int
{
    $userId = null;
    foreach ($args as $arg) {
        if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
            $userId = (int) $m[1];
        }
    }
    if ($userId === null) {
        fwrite(STDERR, "Usage: token:list --user=ID\n");

        return 1;
    }

    $tokens = new ApiTokenRepository($factory->db());
    $rows = $tokens->findAllBy(['user_id' => $userId], orderBy: 'id DESC');
    foreach ($rows as $row) {
        $status = $row['revoked_at'] !== null ? 'revoked' : 'active';
        echo sprintf(
            "#%d %s [%s] last_used=%s expires=%s\n",
            (int) $row['id'],
            (string) $row['name'],
            $status,
            $row['last_used_at'] ?? '-',
            $row['expires_at'] ?? '-'
        );
    }
    if ($rows === []) {
        echo "(no tokens)\n";
    }

    return 0;
}

function tokenCmdHelp(): int
{
    echo <<<'TXT'
API token commands:
  token:issue  --user=ID|--email=ADDR --name=LABEL [--expires=30d]
  token:revoke --id=ID | --token=PLAIN
  token:list   --user=ID

TXT;

    return 0;
}

function tokenParseExpires(string $raw): ?\DateTimeImmutable
{
    if (preg_match('/^(\d+)([dhms])$/i', $raw, $m)) {
        $n = (int) $m[1];
        $unit = strtolower($m[2]);
        $spec = match ($unit) {
            'd' => "P{$n}D",
            'h' => "PT{$n}H",
            'm' => "PT{$n}M",
            's' => "PT{$n}S",
            default => null,
        };
        if ($spec === null) {
            return null;
        }

        return (new \DateTimeImmutable('now'))->add(new \DateInterval($spec));
    }

    try {
        return new \DateTimeImmutable($raw);
    } catch (\Throwable) {
        return null;
    }
}
