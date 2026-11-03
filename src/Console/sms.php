#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SMS CLI: sms:rent | sms:code | sms:release | sms:cancel
 *
 * Usage:
 *   php src/Console/sms.php rent [--account=ID] [--service=ya] [--country=0] [--minutes=20]
 *   php src/Console/sms.php code --phone=ID [--timeout=180]
 *   php src/Console/sms.php release --phone=ID
 *   php src/Console/sms.php cancel --phone=ID
 *
 * Also: php farm sms:rent|sms:code|sms:release|sms:cancel
 */

use Farm\Db\Connection;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__, 2);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
if (isset($argv[0]) && (str_ends_with($argv[0], 'sms.php') || str_ends_with($argv[0], 'farm'))) {
    array_shift($argv);
}

$raw = $argv[0] ?? 'help';
$command = str_starts_with($raw, 'sms:') ? substr($raw, 4) : $raw;
$args = array_slice($argv, 1);

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);

$exit = match ($command) {
    'rent' => smsCmdRent($factory, $args),
    'code' => smsCmdCode($factory, $args),
    'release' => smsCmdRelease($factory, $args),
    'cancel' => smsCmdCancel($factory, $args),
    'help', '--help', '-h' => smsCmdHelp(),
    default => (static function () use ($command): int {
        fwrite(STDERR, "Unknown sms command: {$command}\n");
        smsCmdHelp();

        return 1;
    })(),
};

if (isset($_SERVER['FARM_SMS_AS_LIBRARY']) && $_SERVER['FARM_SMS_AS_LIBRARY']) {
    return $exit;
}

exit($exit);

/**
 * @param list<string> $args
 */
function smsCmdRent(ServiceFactory $factory, array $args): int
{
    $accountId = null;
    $service = null;
    $country = null;
    $minutes = null;

    foreach ($args as $arg) {
        if (preg_match('/^--account=(\d+)$/', $arg, $m)) {
            $accountId = (int) $m[1];
        } elseif (preg_match('/^--service=(.+)$/', $arg, $m)) {
            $service = $m[1];
        } elseif (preg_match('/^--country=(.+)$/', $arg, $m)) {
            $country = $m[1];
        } elseif (preg_match('/^--minutes=(\d+)$/', $arg, $m)) {
            $minutes = (int) $m[1];
        }
    }

    try {
        $sms = $factory->smsProvider();
        $opts = [];
        if ($accountId !== null) {
            $opts['account_id'] = $accountId;
        }
        if ($service !== null) {
            $opts['service'] = $service;
        }
        if ($country !== null) {
            $opts['country'] = $country;
        }
        if ($minutes !== null) {
            $opts['rent_minutes'] = $minutes;
        }

        $result = $sms->getNumber($opts);
    } catch (Throwable $e) {
        fwrite(STDERR, 'sms:rent failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    echo "Rented number\n";
    echo "  phone_id:    {$result['phone_id']}\n";
    echo "  number:      {$result['number']}\n";
    echo "  external_id: {$result['external_id']}\n";
    echo "  provider:    {$result['provider']}\n";
    echo '  account_id:  ' . ($result['account_id'] ?? '-') . "\n";
    echo '  expires_at:  ' . ($result['expires_at'] ?? '-') . "\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function smsCmdCode(ServiceFactory $factory, array $args): int
{
    $phoneId = null;
    $timeout = null;

    foreach ($args as $arg) {
        if (preg_match('/^--phone=(\d+)$/', $arg, $m)) {
            $phoneId = (int) $m[1];
        } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
            $phoneId = (int) $m[1];
        } elseif (preg_match('/^--timeout=(\d+)$/', $arg, $m)) {
            $timeout = (int) $m[1];
        } elseif (preg_match('/^\d+$/', $arg)) {
            $phoneId = (int) $arg;
        }
    }

    if ($phoneId === null) {
        fwrite(STDERR, "Usage: sms:code --phone=ID [--timeout=180]\n");

        return 1;
    }

    try {
        $sms = $factory->smsProvider();
        $result = $sms->waitCode($phoneId, $timeout);
    } catch (Throwable $e) {
        fwrite(STDERR, 'sms:code failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    echo "SMS code received\n";
    echo "  phone_id:   {$result['phone_id']}\n";
    echo "  number:     {$result['number']}\n";
    echo "  code:       {$result['code']}\n";
    echo '  account_id: ' . ($result['account_id'] ?? '-') . "\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function smsCmdRelease(ServiceFactory $factory, array $args): int
{
    $phoneId = smsParsePhoneId($args);
    if ($phoneId === null) {
        fwrite(STDERR, "Usage: sms:release --phone=ID\n");

        return 1;
    }

    try {
        $result = $factory->smsProvider()->release($phoneId);
    } catch (Throwable $e) {
        fwrite(STDERR, 'sms:release failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    echo "Released phone #{$result['phone_id']} status={$result['status']}\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function smsCmdCancel(ServiceFactory $factory, array $args): int
{
    $phoneId = smsParsePhoneId($args);
    if ($phoneId === null) {
        fwrite(STDERR, "Usage: sms:cancel --phone=ID\n");

        return 1;
    }

    try {
        $result = $factory->smsProvider()->cancel($phoneId);
    } catch (Throwable $e) {
        fwrite(STDERR, 'sms:cancel failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    echo "Cancelled phone #{$result['phone_id']} status={$result['status']}\n";

    return 0;
}

/**
 * @param list<string> $args
 */
function smsParsePhoneId(array $args): ?int
{
    foreach ($args as $arg) {
        if (preg_match('/^--phone=(\d+)$/', $arg, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\d+$/', $arg)) {
            return (int) $arg;
        }
    }

    return null;
}

function smsCmdHelp(): int
{
    echo <<<'HELP'
SMS provider CLI

Commands:
  sms:rent [--account=ID] [--service=ya] [--country=0] [--minutes=20]
      Rent a number (SMS_PROVIDER / SMS_KEY). Optionally bind to account.

  sms:code --phone=ID [--timeout=180]
      Wait for SMS code, store on phones.last_code, keep account.phone set.

  sms:release --phone=ID
      Mark activation done and release number at provider.

  sms:cancel --phone=ID
      Cancel unused activation.

Env:
  SMS_PROVIDER=sms-activate|simbank
  SMS_KEY=...
  SMS_API_URL=... (optional; required override for remote simbank)
  SMS_SERVICE=ya
  SMS_COUNTRY=0
  SMS_RENT_MINUTES=20

HELP;

    return 0;
}
