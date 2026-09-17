<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rbac;
use PHPUnit\Framework\TestCase;

final class RbacTest extends TestCase
{
    public function testHigherRoleCoversLowerOne(): void
    {
        $this->assertTrue(Rbac::atLeast(Rbac::ADMIN, Rbac::MANAGER));
        $this->assertTrue(Rbac::atLeast(Rbac::MANAGER, Rbac::MANAGER));
        $this->assertFalse(Rbac::atLeast(Rbac::OPERATOR, Rbac::MANAGER));
    }

    public function testOnlyManagerAndAdminSeeAllRecords(): void
    {
        $this->assertFalse(Rbac::seesAllRecords(Rbac::OPERATOR));
        $this->assertTrue(Rbac::seesAllRecords(Rbac::MANAGER));
        $this->assertTrue(Rbac::seesAllRecords(Rbac::ADMIN));
    }

    public function testUnknownOrMissingRoleGetsNoAccess(): void
    {
        $this->assertFalse(Rbac::seesAllRecords(null));
        $this->assertFalse(Rbac::seesAllRecords('GUEST'));
        $this->assertSame(0, Rbac::rank('GUEST'));
        $this->assertFalse(Rbac::isRole('GUEST'));
    }

    public function testRoleNameIsCaseInsensitive(): void
    {
        $this->assertTrue(Rbac::isRole('admin'));
        $this->assertTrue(Rbac::seesAllRecords(' manager '));
        $this->assertSame(['OPERATOR', 'MANAGER', 'ADMIN'], Rbac::roles());
    }

    public function testActionPermissionsFollowTheRoleHierarchy(): void
    {
        $this->assertTrue(Rbac::can(Rbac::OPERATOR, 'certificates.create'));
        $this->assertFalse(Rbac::can(Rbac::OPERATOR, 'certificates.archive'));
        $this->assertFalse(Rbac::can(Rbac::OPERATOR, 'certificates.assign_owner'));

        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'certificates.archive'));
        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'archive.view'));
        $this->assertFalse(Rbac::can(Rbac::MANAGER, 'accounts.manage'));

        $this->assertTrue(Rbac::can(Rbac::ADMIN, 'accounts.manage'));
        $this->assertTrue(Rbac::can('admin', 'payers.archive'));
    }

    public function testUnknownPermissionOrRoleIsDenied(): void
    {
        $this->assertFalse(Rbac::can(Rbac::ADMIN, 'certificates.delete'));
        $this->assertFalse(Rbac::can('GUEST', 'certificates.view'));
        $this->assertFalse(Rbac::can(null, 'certificates.view'));
    }

    public function testPermissionListGrowsWithRank(): void
    {
        $operator = Rbac::permissionsFor(Rbac::OPERATOR);
        $manager = Rbac::permissionsFor(Rbac::MANAGER);
        $admin = Rbac::permissionsFor(Rbac::ADMIN);

        $this->assertSame([], array_diff($operator, $manager));
        $this->assertSame([], array_diff($manager, $admin));
        $this->assertContains('accounts.manage', $admin);
        $this->assertNotContains('accounts.manage', $manager);
        $this->assertSame([], Rbac::permissionsFor('GUEST'));
    }
}
