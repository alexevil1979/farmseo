<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config.php';

use Farm\Db\Connection;
use Farm\Db\Migrator;

$host = $config['db']['host'];
$port = (int) $config['db']['port'];
$user = $config['db']['username'];
$pass = $config['db']['password'];
$testDb = $config['db']['test_database'];

$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $testDb) . '`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

Connection::reset();
$testConfig = $config['db'];
$testConfig['database'] = $testDb;
$db = Connection::reconnect($testConfig);

// Fresh schema for the test run
$migrator = new Migrator($db, $root . DIRECTORY_SEPARATOR . 'migrations');
// Drop all known tables for clean slate
$tables = [
    'runs_events', 'task_runs', 'tasks',
    'targets', 'keywords', 'campaigns',
    'scenario_schedules', 'scenario_steps', 'scenarios',
    'proxy_assignments',
    'phones',
    'profile_migrations',
    'account_cookies_snapshots', 'account_sessions', 'account_fingerprints', 'accounts', 'proxies',
    'nodes',
    'alerts', 'metrics', 'blacklists',
    'settings', 'audit_log', 'api_tokens',
    'users', 'permissions', 'roles',
    'schema_migrations',
];
$pdoDb = $db->pdo();
$pdoDb->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $pdoDb->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdoDb->exec('SET FOREIGN_KEY_CHECKS=1');

$migrator->migrate();

// Expose for tests
$GLOBALS['farm_test_db'] = $db;
$GLOBALS['farm_test_config'] = $testConfig;
