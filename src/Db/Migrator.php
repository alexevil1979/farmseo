<?php

declare(strict_types=1);

namespace Farm\Db;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies / rolls back SQL migrations. Tracks applied files in schema_migrations.
 *
 * Each migration file must contain sections:
 *   -- migrate:up
 *   ...
 *   -- migrate:down
 *   ...
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath,
    ) {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException("Migrations path not found: {$this->migrationsPath}");
        }
    }

    public function ensureMigrationsTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT UNSIGNED NOT NULL,
    `applied_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $this->connection->pdo()->exec($sql);
    }

    /**
     * Apply all pending migrations. Returns list of applied file basenames.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $files = $this->discoverMigrationFiles();
        $pending = array_values(array_filter(
            $files,
            static fn (string $f): bool => !isset($applied[$f])
        ));

        if ($pending === []) {
            return [];
        }

        $done = [];

        foreach ($pending as $file) {
            $path = $this->migrationsPath . DIRECTORY_SEPARATOR . $file;
            $sections = $this->parseMigrationFile($path);
            if ($sections['up'] === '') {
                throw new RuntimeException("Migration {$file} has empty -- migrate:up section");
            }

            // One batch per file so migrate:rollback undoes a single migration by default.
            $batch = $this->nextBatch();
            $pdo = $this->connection->pdo();
            // MySQL/MariaDB auto-commits DDL; wrap DML tracking only when a real txn exists.
            $useTxn = $this->beginIfSupported($pdo);
            try {
                $this->execStatements($pdo, $sections['up']);
                $stmt = $pdo->prepare(
                    'INSERT INTO `' . self::TABLE . '` (`migration`, `batch`) VALUES (?, ?)'
                );
                $stmt->execute([$file, $batch]);
                if ($useTxn && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                $done[] = $file;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Best-effort compensate: run down section after failed up (DDL may be partial).
                try {
                    if ($sections['down'] !== '') {
                        $this->execStatements($pdo, $sections['down']);
                    }
                } catch (Throwable) {
                    // ignore compensation errors; surface original
                }
                throw new RuntimeException(
                    "Failed migrating {$file}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $done;
    }

    /**
     * Roll back the last batch (or $steps batches). Returns rolled-back file basenames.
     *
     * @return list<string>
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTable();
        if ($steps < 1) {
            throw new RuntimeException('Rollback steps must be >= 1');
        }

        $rolled = [];
        for ($i = 0; $i < $steps; $i++) {
            $batch = $this->lastBatch();
            if ($batch === null) {
                break;
            }

            $files = $this->migrationsInBatch($batch);
            // Reverse order within batch
            $files = array_reverse($files);

            foreach ($files as $file) {
                $path = $this->migrationsPath . DIRECTORY_SEPARATOR . $file;
                if (!is_readable($path)) {
                    throw new RuntimeException("Migration file missing for rollback: {$file}");
                }
                $sections = $this->parseMigrationFile($path);
                if ($sections['down'] === '') {
                    throw new RuntimeException("Migration {$file} has empty -- migrate:down section");
                }

                $pdo = $this->connection->pdo();
                $useTxn = $this->beginIfSupported($pdo);
                try {
                    $this->execStatements($pdo, $sections['down']);
                    $stmt = $pdo->prepare(
                        'DELETE FROM `' . self::TABLE . '` WHERE `migration` = ?'
                    );
                    $stmt->execute([$file]);
                    if ($useTxn && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $rolled[] = $file;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new RuntimeException(
                        "Failed rolling back {$file}: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        return $rolled;
    }

    /**
     * @return array<string, true> basename => true
     */
    public function appliedMigrations(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT `migration` FROM `' . self::TABLE . '` ORDER BY `id` ASC'
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration']] = true;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function discoverMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $basenames = array_map(static fn (string $p): string => basename($p), $files);
        sort($basenames, SORT_STRING);

        return array_values($basenames);
    }

    /**
     * @return array{up: string, down: string}
     */
    public function parseMigrationFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read migration: {$path}");
        }

        $up = '';
        $down = '';
        $section = null;

        foreach (preg_split("/\r\n|\n|\r/", $content) as $line) {
            if (preg_match('/^\s*--\s*migrate:up\s*$/i', $line)) {
                $section = 'up';
                continue;
            }
            if (preg_match('/^\s*--\s*migrate:down\s*$/i', $line)) {
                $section = 'down';
                continue;
            }
            if ($section === 'up') {
                $up .= $line . "\n";
            } elseif ($section === 'down') {
                $down .= $line . "\n";
            }
        }

        return [
            'up' => trim($up),
            'down' => trim($down),
        ];
    }

    private function nextBatch(): int
    {
        $row = $this->connection->fetchOne(
            'SELECT COALESCE(MAX(`batch`), 0) AS `m` FROM `' . self::TABLE . '`'
        );

        return ((int) ($row['m'] ?? 0)) + 1;
    }

    private function lastBatch(): ?int
    {
        $row = $this->connection->fetchOne(
            'SELECT MAX(`batch`) AS `m` FROM `' . self::TABLE . '`'
        );
        if ($row === null || $row['m'] === null) {
            return null;
        }

        return (int) $row['m'];
    }

    /**
     * @return list<string>
     */
    private function migrationsInBatch(int $batch): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT `migration` FROM `' . self::TABLE . '` WHERE `batch` = ? ORDER BY `id` ASC',
            [$batch]
        );

        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /**
     * Start a transaction when the engine still has one after DDL-less begin.
     * Returns false when MySQL implicitly closed it (or begin is unsupported).
     */
    private function beginIfSupported(PDO $pdo): bool
    {
        try {
            if ($pdo->inTransaction()) {
                return true;
            }
            $pdo->beginTransaction();

            return $pdo->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    private function execStatements(PDO $pdo, string $sql): void
    {
        $statements = $this->splitSqlStatements($sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Line comments
            if (!$inSingle && !$inDouble && !$inBacktick && $ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Block comments
            if (!$inSingle && !$inDouble && !$inBacktick && $ch === '/' && $next === '*') {
                $i += 2;
                while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }
}
