<?php

declare(strict_types=1);

namespace Farm\Repositories;

use Farm\Db\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Base CRUD repository. Column names stay snake_case as in CONTRACT.md.
 *
 * @phpstan-type Row array<string, mixed>
 */
abstract class AbstractRepository
{
    abstract protected function table(): string;

    /** @return list<string> */
    abstract protected function fillable(): array;

    public function __construct(protected readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $criteria column => value (AND)
     * @return Row|null
     */
    public function findBy(array $criteria): ?array
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('findBy requires at least one criterion');
        }

        [$where, $params] = $this->buildWhere($criteria);
        $sql = sprintf('SELECT * FROM `%s` WHERE %s LIMIT 1', $this->table(), $where);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return list<Row>
     */
    public function findAllBy(array $criteria, ?int $limit = null, ?int $offset = null, ?string $orderBy = null): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        $sql = sprintf('SELECT * FROM `%s` WHERE %s', $this->table(), $where);
        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($orderBy);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function findById(int|string $id): ?array
    {
        return $this->findBy(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $criteria empty = all rows
     * @return list<Row>
     */
    public function getAll(array $criteria = [], ?int $limit = null, ?int $offset = null, ?string $orderBy = 'id ASC'): array
    {
        if ($criteria === []) {
            $sql = sprintf('SELECT * FROM `%s`', $this->table());
            $params = [];
        } else {
            [$where, $params] = $this->buildWhere($criteria);
            $sql = sprintf('SELECT * FROM `%s` WHERE %s', $this->table(), $where);
        }
        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($orderBy);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        if ($criteria === []) {
            $row = $this->db->fetchOne(sprintf('SELECT COUNT(*) AS `c` FROM `%s`', $this->table()));
        } else {
            [$where, $params] = $this->buildWhere($criteria);
            $row = $this->db->fetchOne(
                sprintf('SELECT COUNT(*) AS `c` FROM `%s` WHERE %s', $this->table(), $where),
                $params
            );
        }

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     * @return int inserted id
     */
    public function create(array $data): int
    {
        $filtered = $this->filterFillable($data);
        if ($filtered === []) {
            throw new InvalidArgumentException('create() received no fillable columns');
        }

        $filtered = $this->encodeJsonColumns($filtered);
        $cols = array_keys($filtered);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table(),
            implode('`, `', $cols),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($filtered as $k => $v) {
            $params[':' . $k] = $v;
        }

        $this->db->execute($sql, $params);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): bool
    {
        $filtered = $this->filterFillable($data);
        unset($filtered['id']);
        if ($filtered === []) {
            return false;
        }

        $filtered = $this->encodeJsonColumns($filtered);
        $sets = [];
        $params = [':id' => $id];
        foreach ($filtered as $col => $val) {
            $sets[] = sprintf('`%s` = :%s', $col, $col);
            $params[':' . $col] = $val;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `id` = :id',
            $this->table(),
            implode(', ', $sets)
        );
        $stmt = $this->db->execute($sql, $params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int|string $id): bool
    {
        $stmt = $this->db->execute(
            sprintf('DELETE FROM `%s` WHERE `id` = ?', $this->table()),
            [$id]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Insert ignoring unique conflicts (MySQL INSERT IGNORE).
     *
     * @param array<string, mixed> $data
     * @return int|null id if inserted, null if ignored
     */
    public function createIgnore(array $data): ?int
    {
        $filtered = $this->filterFillable($data);
        if ($filtered === []) {
            throw new InvalidArgumentException('createIgnore() received no fillable columns');
        }
        $filtered = $this->encodeJsonColumns($filtered);
        $cols = array_keys($filtered);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT IGNORE INTO `%s` (`%s`) VALUES (%s)',
            $this->table(),
            implode('`, `', $cols),
            implode(', ', $placeholders)
        );
        $params = [];
        foreach ($filtered as $k => $v) {
            $params[':' . $k] = $v;
        }
        $stmt = $this->db->execute($sql, $params);
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return (int) $this->db->lastInsertId();
    }

    /** @return list<string> JSON column names for this table */
    protected function jsonColumns(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterFillable(array $data): array
    {
        $allowed = array_flip($this->fillable());
        $out = [];
        foreach ($data as $k => $v) {
            if (isset($allowed[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeJsonColumns(array $data): array
    {
        foreach ($this->jsonColumns() as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $val = $data[$col];
            if ($val === null) {
                continue;
            }
            if (is_string($val)) {
                // assume already JSON
                continue;
            }
            $encoded = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $data[$col] = $encoded;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function buildWhere(array $criteria): array
    {
        $parts = [];
        $params = [];
        $i = 0;
        foreach ($criteria as $col => $value) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', (string) $col)) {
                throw new InvalidArgumentException("Invalid column name: {$col}");
            }
            $ph = ':w' . $i;
            if ($value === null) {
                $parts[] = sprintf('`%s` IS NULL', $col);
            } else {
                $parts[] = sprintf('`%s` = %s', $col, $ph);
                $params[$ph] = $value;
            }
            $i++;
        }

        return [implode(' AND ', $parts), $params];
    }

    protected function sanitizeOrderBy(string $orderBy): string
    {
        // Allow: "col ASC, col2 DESC"
        $chunks = array_map('trim', explode(',', $orderBy));
        $safe = [];
        foreach ($chunks as $chunk) {
            if (!preg_match('/^([a-z_][a-z0-9_]*)(\s+(ASC|DESC))?$/i', $chunk, $m)) {
                throw new InvalidArgumentException("Invalid ORDER BY: {$orderBy}");
            }
            $dir = isset($m[2]) ? strtoupper(trim($m[2])) : 'ASC';
            $safe[] = sprintf('`%s` %s', $m[1], $dir);
        }

        return implode(', ', $safe);
    }

    protected function requireRow(?array $row, string $context): array
    {
        if ($row === null) {
            throw new RuntimeException($context . ' not found');
        }

        return $row;
    }
}
