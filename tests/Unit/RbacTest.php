<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rbac;
use PHPUnit\Framework\TestCase;

final class RbacTest extends TestCase
{
    public function testRolesAreCompanyPositionsFromWidestToNarrowest(): void
    {
        $this->assertSame(
            ['ADMIN', 'DIRECTOR', 'MANAGER', 'ACCOUNTANT', 'IT', 'OPERATOR', 'EMPLOYEE'],
            Rbac::roles()
        );
    }

    public function testDataScopePerRole(): void
    {
        $this->assertSame(Rbac::SCOPE_ALL, Rbac::scope(Rbac::ADMIN));
        $this->assertSame(Rbac::SCOPE_ALL, Rbac::scope(Rbac::DIRECTOR));
        $this->assertSame(Rbac::SCOPE_ALL, Rbac::scope(Rbac::MANAGER));
        $this->assertSame(Rbac::SCOPE_ALL, Rbac::scope(Rbac::ACCOUNTANT));
        $this->assertSame(Rbac::SCOPE_TECHNICAL, Rbac::scope(Rbac::IT));
        $this->assertSame(Rbac::SCOPE_OWN, Rbac::scope(Rbac::OPERATOR));
        $this->assertSame(Rbac::SCOPE_PERSONAL, Rbac::scope(Rbac::EMPLOYEE));
    }

    public function testOnlyOrganisationWideRolesSeeAllRecords(): void
    {
        foreach ([Rbac::ADMIN, Rbac::DIRECTOR, Rbac::MANAGER, Rbac::ACCOUNTANT] as $role) {
            $this->assertTrue(Rbac::seesAllRecords($role), $role);
        }
        foreach ([Rbac::IT, Rbac::OPERATOR, Rbac::EMPLOYEE] as $role) {
            $this->assertFalse(Rbac::seesAllRecords($role), $role);
        }
    }

    public function testUnknownOrMissingRoleGetsNoAccess(): void
    {
        $this->assertFalse(Rbac::seesAllRecords(null));
        $this->assertFalse(Rbac::seesAllRecords('GUEST'));
        $this->assertFalse(Rbac::isRole('GUEST'));
        $this->assertSame(Rbac::SCOPE_PERSONAL, Rbac::scope('GUEST'));
        $this->assertSame([], Rbac::permissionsFor('GUEST'));
    }

    public function testRoleNameIsCaseInsensitive(): void
    {
        $this->assertTrue(Rbac::isRole('admin'));
        $this->assertTrue(Rbac::isRole(' accountant '));
        $this->assertTrue(Rbac::seesAllRecords(' manager '));
        $this->assertTrue(Rbac::isAdmin('Admin'));
        $this->assertFalse(Rbac::isAdmin(Rbac::MANAGER));
    }

    public function testAdministratorMayDoEverythingAndOnlyHeMayManageTheSystem(): void
    {
        foreach (Rbac::allPermissions() as $permission) {
            $this->assertTrue(Rbac::can(Rbac::ADMIN, $permission), $permission);
        }

        foreach (['accounts.manage', 'settings.manage', 'import.run', 'templates.manage', 'attachments.manage', 'events.view_all', 'notifications.broadcast'] as $permission) {
            foreach (array_diff(Rbac::roles(), [Rbac::ADMIN]) as $role) {
                $this->assertFalse(Rbac::can($role, $permission), "{$role} / {$permission}");
            }
        }
    }

    public function testManagerAndOperatorKeepTheirEtap2Permissions(): void
    {
        $this->assertTrue(Rbac::can(Rbac::OPERATOR, 'certificates.create'));
        $this->assertFalse(Rbac::can(Rbac::OPERATOR, 'certificates.archive'));
        $this->assertFalse(Rbac::can(Rbac::OPERATOR, 'certificates.assign_owner'));
        $this->assertTrue(Rbac::can(Rbac::OPERATOR, 'invitations.send'));

        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'certificates.archive'));
        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'archive.view'));
        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'tasks.assign'));
        $this->assertTrue(Rbac::can(Rbac::MANAGER, 'export.run'));
        $this->assertFalse(Rbac::can(Rbac::MANAGER, 'accounts.manage'));
    }

    public function testCompanyPositionsHaveTheirOwnProfiles(): void
    {
        // Szef widzi i rozdziela pracę, ale nie edytuje rekordów.
        $this->assertTrue(Rbac::can(Rbac::DIRECTOR, 'reports.view'));
        $this->assertTrue(Rbac::can(Rbac::DIRECTOR, 'tasks.assign'));
        $this->assertTrue(Rbac::can(Rbac::DIRECTOR, 'certificates.assign_owner'));
        $this->assertFalse(Rbac::can(Rbac::DIRECTOR, 'certificates.update'));
        $this->assertFalse(Rbac::can(Rbac::DIRECTOR, 'certificates.create'));

        // Księgowa zmienia płatności i dane firm, ale nie certyfikaty.
        $this->assertTrue(Rbac::can(Rbac::ACCOUNTANT, 'certificates.update_payment'));
        $this->assertTrue(Rbac::can(Rbac::ACCOUNTANT, 'payers.update'));
        $this->assertTrue(Rbac::can(Rbac::ACCOUNTANT, 'export.run'));
        $this->assertFalse(Rbac::can(Rbac::ACCOUNTANT, 'certificates.update'));
        $this->assertFalse(Rbac::can(Rbac::ACCOUNTANT, 'invitations.send'));

        // Informatyk pracuje na certyfikatach technicznych i zadaniach, bez osób i firm.
        $this->assertTrue(Rbac::can(Rbac::IT, 'certificates.create'));
        $this->assertTrue(Rbac::can(Rbac::IT, 'tasks.update'));
        $this->assertFalse(Rbac::can(Rbac::IT, 'beneficiaries.create'));
        $this->assertFalse(Rbac::can(Rbac::IT, 'payers.update'));

        // Operator obsługuje wnioski z e-maila.
        $this->assertTrue(Rbac::can(Rbac::OPERATOR, 'registrations.review'));
        $this->assertTrue(Rbac::can(Rbac::OPERATOR, 'registrations.intake'));
        $this->assertFalse(Rbac::can(Rbac::ACCOUNTANT, 'registrations.view'));
    }

    public function testEmployeeCanOnlyReadAndUseNotifications(): void
    {
        $writes = array_filter(
            Rbac::permissionsFor(Rbac::EMPLOYEE),
            static fn (string $permission): bool => !str_ends_with($permission, '.view')
                && !in_array($permission, ['search.use', 'notifications.use'], true)
        );

        $this->assertSame([], array_values($writes));
        $this->assertTrue(Rbac::can(Rbac::EMPLOYEE, 'certificates.view'));
        $this->assertTrue(Rbac::can(Rbac::EMPLOYEE, 'payers.view'));
        $this->assertTrue(Rbac::can(Rbac::EMPLOYEE, 'notifications.use'));
    }

    public function testEveryRoleMayUseNotifications(): void
    {
        foreach (Rbac::roles() as $role) {
            $this->assertTrue(Rbac::can($role, 'notifications.use'), $role);
        }
    }

    public function testUnknownPermissionOrRoleIsDenied(): void
    {
        $this->assertFalse(Rbac::can(Rbac::ADMIN, 'certificates.delete'));
        $this->assertFalse(Rbac::can('GUEST', 'certificates.view'));
        $this->assertFalse(Rbac::can(null, 'certificates.view'));
    }

    public function testTechnicalRoleHandlesOnlyTechnicalCertificateTypes(): void
    {
        $this->assertTrue(Rbac::canManageCertificateType(Rbac::IT, 'SSL_CERTIFICATE'));
        $this->assertTrue(Rbac::canManageCertificateType(Rbac::IT, 'DOMAIN'));
        $this->assertFalse(Rbac::canManageCertificateType(Rbac::IT, 'QUALIFIED_SIGNATURE'));
        $this->assertFalse(Rbac::canManageCertificateType(Rbac::IT, 'QUALIFIED_SEAL'));
        $this->assertTrue(Rbac::canManageCertificateType(Rbac::OPERATOR, 'QUALIFIED_SIGNATURE'));
        $this->assertTrue(Rbac::canManageCertificateType(Rbac::ADMIN, 'QUALIFIED_SEAL'));
    }
}
