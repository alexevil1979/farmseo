<?php

declare(strict_types=1);

namespace Farm\Db;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton PDO wrapper — utf8mb4, strict SQL mode, real prepares, exceptions.
 */
final class Connection
{
    private static ?self $instance = null;

    private PDO $pdo;

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     username?: string,
     *     password?: string,
     *     charset?: string
     * } $config
     */
    private function __construct(array $config)
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 3306);
        $database = $config['database'] ?? 'farm';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->pdo->exec("SET NAMES {$charset} COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     username?: string,
     *     password?: string,
     *     charset?: string
     * }|null $config
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                throw new RuntimeException('Connection must be bootstrapped with config on first call.');
            }
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Replace singleton (tests / CLI database switch).
     *
     * @param array{
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     username?: string,
     *     password?: string,
     *     charset?: string
     * } $config
     */
    public static function reconnect(array $config): self
    {
        self::$instance = new self($config);

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }

        return false;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->execute($sql, $params)->fetchAll();

        return $rows;
    }
}
