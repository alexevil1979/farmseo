<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class ProxyRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'proxies';
    }

    protected function fillable(): array
    {
        return [
            'host',
            'port',
            'protocol',
            'username',
            'password_enc',
            'country',
            'health',
            'latency_ms',
            'last_check_at',
            'is_active',
            'meta',
            'created_at',
            'updated_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->findAllBy(['is_active' => 1], orderBy: 'id ASC');
    }

    public function findByHostPortProtocol(string $host, int $port, string $protocol): ?array
    {
        return $this->findBy([
            'host' => $host,
            'port' => $port,
            'protocol' => $protocol,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findHealthy(): array
    {
        return $this->findAllBy(
            ['health' => 'ok', 'is_active' => 1],
            orderBy: 'latency_ms ASC, id ASC'
        );
    }

    /**
     * Free healthy (or unknown) proxies for sticky assignment, optional country filter.
     * Excludes proxies that already have is_current=1 assignment.
     *
     * @param list<string> $healthAllow
     * @return list<array<string, mixed>>
     */
    public function findAvailable(?string $country = null, array $healthAllow = ['ok', 'slow', 'unknown'], int $limit = 50): array
    {
        $healthAllow = array_values(array_filter($healthAllow, static fn ($h) => is_string($h) && $h !== ''));
        if ($healthAllow === []) {
            $healthAllow = ['ok'];
        }

        $placeholders = [];
        $params = [];
        foreach ($healthAllow as $i => $h) {
            $ph = ':h' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $h;
        }

        $sql = <<<'SQL'
            SELECT p.*
            FROM `proxies` p
            LEFT JOIN `proxy_assignments` pa
                ON pa.`proxy_id` = p.`id` AND pa.`is_current` = 1
            WHERE p.`is_active` = 1
              AND pa.`id` IS NULL
              AND p.`health` IN (%s)
            SQL;
        $sql = sprintf($sql, implode(', ', $placeholders));

        if ($country !== null && $country !== '') {
            $sql .= ' AND p.`country` = :country';
            $params[':country'] = strtoupper(substr($country, 0, 2));
        }

        $sql .= ' ORDER BY FIELD(p.`health`, \'ok\', \'slow\', \'unknown\'), p.`latency_ms` IS NULL, p.`latency_ms` ASC, p.`id` ASC';
        $sql .= ' LIMIT ' . max(1, $limit);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, int> health => count
     */
    public function healthStats(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT `health`, COUNT(*) AS `c`
                FROM `proxies`
                GROUP BY `health`
                SQL
        );
        $out = ['ok' => 0, 'slow' => 0, 'dead' => 0, 'unknown' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['health']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * @return array{total: int, active: int, assigned: int, free: int, by_country: array<string, int>, by_health: array<string, int>}
     */
    public function poolStats(): array
    {
        $total = $this->count();
        $active = $this->count(['is_active' => 1]);
        $assignedRow = $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS `c` FROM `proxy_assignments` WHERE `is_current` = 1
                SQL
        );
        $assigned = (int) ($assignedRow['c'] ?? 0);

        $byCountryRows = $this->db->fetchAll(
            <<<'SQL'
                SELECT COALESCE(`country`, '??') AS `country`, COUNT(*) AS `c`
                FROM `proxies`
                WHERE `is_active` = 1
                GROUP BY COALESCE(`country`, '??')
                ORDER BY `c` DESC
                SQL
        );
        $byCountry = [];
        foreach ($byCountryRows as $row) {
            $byCountry[(string) $row['country']] = (int) $row['c'];
        }

        return [
            'total' => $total,
            'active' => $active,
            'assigned' => $assigned,
            'free' => max(0, $active - $assigned),
            'by_country' => $byCountry,
            'by_health' => $this->healthStats(),
        ];
    }
}
