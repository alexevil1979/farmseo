<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Services\Rbac;
use PHPUnit\Framework\TestCase;

final class RbacTest extends TestCase
{
    public function testViewerCannotWrite(): void
    {
        $rbac = new Rbac();
        self::assertTrue($rbac->can('viewer', 'tasks', 'read'));
        self::assertFalse($rbac->can('viewer', 'tasks', 'write'));
        self::assertFalse($rbac->can('viewer', 'users', 'delete'));
    }

    public function testManagerCannotDeleteUsers(): void
    {
        $rbac = new Rbac();
        self::assertTrue($rbac->can('manager', 'tasks', 'write'));
        self::assertTrue($rbac->can('manager', 'users', 'read'));
        self::assertTrue($rbac->can('manager', 'users', 'write'));
        self::assertFalse($rbac->can('manager', 'users', 'delete'));
    }

    public function testAdminCanEverything(): void
    {
        $rbac = new Rbac();
        self::assertTrue($rbac->can('admin', 'users', 'delete'));
        self::assertTrue($rbac->can('admin', 'tasks', 'run'));
        self::assertTrue($rbac->can('admin', 'roles', 'write'));
    }
}
