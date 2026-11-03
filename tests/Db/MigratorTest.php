<?php

declare(strict_types=1);

namespace Farm\Tests\Db;

use Farm\Db\Connection;
use Farm\Db\Migrator;
use Farm\Tests\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrateIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $migrator = new Migrator($this->db, $root . DIRECTORY_SEPARATOR . 'migrations');
        $again = $migrator->migrate();
        self::assertSame([], $again);
    }

    public function testRollbackAndRemigrate(): void
    {
        $root = dirname(__DIR__, 2);
        $migrator = new Migrator($this->db, $root . DIRECTORY_SEPARATOR . 'migrations');

        $rolled = $migrator->rollback(1);
        self::assertNotEmpty($rolled);

        $applied = $migrator->migrate();
        self::assertSame($rolled, array_reverse($applied));

        // restore full schema for sibling tests — re-apply any missing
        $migrator->migrate();
        // Refresh global connection state: schema must be complete
        $tables = $this->db->fetchAll('SHOW TABLES');
        self::assertGreaterThanOrEqual(20, count($tables));
    }

    public function testConnectionStrictAndUtf8(): void
    {
        $mode = $this->db->fetchOne('SELECT @@SESSION.sql_mode AS m');
        self::assertNotNull($mode);
        self::assertStringContainsString('STRICT', (string) $mode['m']);

        $charset = $this->db->fetchOne('SELECT @@character_set_connection AS c');
        self::assertSame('utf8mb4', $charset['c'] ?? null);
    }
}
