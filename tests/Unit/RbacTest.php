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
}
