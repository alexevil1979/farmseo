<?php

declare(strict_types=1);

namespace Farm\Repositories;

final class UserRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function fillable(): array
    {
        return [
            'role_id',
            'email',
            'password_hash',
            'name',
            'is_active',
            'last_login_at',
            'created_at',
            'updated_at',
        ];
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findBy(['email' => $email]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveAdmins(): array
    {
        $sql = <<<'SQL'
            SELECT u.*
            FROM `users` u
            INNER JOIN `roles` r ON r.`id` = u.`role_id`
            WHERE r.`name` = :role_name
              AND u.`is_active` = 1
            ORDER BY u.`id` ASC
            SQL;

        return $this->db->fetchAll($sql, [':role_name' => 'admin']);
    }
}
