<?php

declare(strict_types=1);

namespace Farm\Tests;

use Farm\Db\Connection;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Connection $db */
        $db = $GLOBALS['farm_test_db'];
        $this->db = $db;
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->truncateTables() as $table) {
            $this->db->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return list<string> */
    protected function truncateTables(): array
    {
        return [
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
        ];
    }
}
