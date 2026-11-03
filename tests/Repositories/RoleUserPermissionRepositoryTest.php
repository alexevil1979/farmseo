<?php

declare(strict_types=1);

namespace Farm\Tests\Repositories;

use Farm\Repositories\PermissionRepository;
use Farm\Repositories\RoleRepository;
use Farm\Repositories\UserRepository;
use Farm\Tests\TestCase;

final class RoleUserPermissionRepositoryTest extends TestCase
{
    public function testRoleEnsureAndFindByName(): void
    {
        $roles = new RoleRepository($this->db);
        $id1 = $roles->ensure('admin', 'Full access');
        $id2 = $roles->ensure('admin', 'ignored');
        self::assertSame($id1, $id2);
        self::assertSame('admin', $roles->findByName('admin')['name']);
    }

    public function testPermissionGrantIdempotent(): void
    {
        $roles = new RoleRepository($this->db);
        $perms = new PermissionRepository($this->db);
        $roleId = $roles->ensure('manager');

        $p1 = $perms->grant($roleId, 'tasks', 'run');
        $p2 = $perms->grant($roleId, 'tasks', 'run');
        self::assertSame($p1, $p2);
        self::assertCount(1, $perms->findByRole($roleId));
    }

    public function testUserCrudAndFindByEmail(): void
    {
        $roles = new RoleRepository($this->db);
        $users = new UserRepository($this->db);
        $roleId = $roles->ensure('admin');

        $id = $users->create([
            'role_id' => $roleId,
            'email' => 'a@farm.test',
            'password_hash' => 'hash',
            'name' => 'Admin',
            'is_active' => 1,
        ]);
        self::assertGreaterThan(0, $id);

        $found = $users->findByEmail('a@farm.test');
        self::assertNotNull($found);
        self::assertSame('Admin', $found['name']);

        self::assertTrue($users->update($id, ['name' => 'Root']));
        self::assertSame('Root', $users->findById($id)['name']);

        $admins = $users->findActiveAdmins();
        self::assertNotEmpty($admins);

        self::assertTrue($users->delete($id));
        self::assertNull($users->findById($id));
    }
}
