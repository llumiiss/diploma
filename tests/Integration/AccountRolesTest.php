<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\AccountService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

/**
 * Konta z rolami stanowisk i powiązaniem pracownika z użytkownikiem certyfikatu (Etap 10).
 */
final class AccountRolesTest extends IntegrationTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        static $sequence = 0;
        ++$sequence;

        return array_merge([
            'first_name' => 'Anna',
            'last_name'  => 'Nowak' . $sequence,
            'email'      => 'anna.nowak' . $sequence . '@example.com',
            'role'       => 'EMPLOYEE',
        ], $overrides);
    }

    public function testEveryCompanyRoleCanBeAssigned(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AccountService($this->db);

        foreach (Rbac::roles() as $role) {
            $this->assertSame($role, $service->create($admin, $this->data(['role' => $role]))['role']);
        }

        try {
            $service->create($admin, $this->data(['role' => 'GUEST']));
            $this->fail('Nieznana rola.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('role', $e->errors);
        }
    }

    public function testEmployeeCanBeLinkedToACertificateUser(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AccountService($this->db);
        $personId = $this->insertBeneficiary($this->insertPayer(), ['first_name' => 'Piotr', 'last_name' => 'Lewandowski']);

        $account = $service->create($admin, $this->data(['beneficiary_id' => $personId]));
        $this->assertSame($personId, $account['beneficiary_id']);
        $this->assertSame('Piotr Lewandowski', $account['beneficiary_name']);
        $this->assertSame($personId, $service->list($admin)[0]['beneficiary_id']);

        // Rola inna niż pracownik nie przechowuje powiązania — ma szerszy zakres i go nie potrzebuje.
        $promoted = $service->update($admin, $account['id'], [
            'first_name' => $account['first_name'], 'last_name' => $account['last_name'], 'email' => $account['email'],
            'role' => 'OPERATOR', 'beneficiary_id' => $personId,
        ]);
        $this->assertNull($promoted['beneficiary_id']);
    }

    public function testLinkedPersonMustExistAndBeActive(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AccountService($this->db);
        $personId = $this->insertBeneficiary($this->insertPayer());
        $this->db->exec("UPDATE beneficiaries SET archived_at = NOW() WHERE id = {$personId}");

        foreach ([$personId, 999999] as $badId) {
            try {
                $service->create($admin, $this->data(['beneficiary_id' => $badId]));
                $this->fail('Powiązanie z nieistniejącą albo zarchiwizowaną osobą.');
            } catch (ServiceException $e) {
                $this->assertArrayHasKey('beneficiary_id', $e->errors);
            }
        }
    }

    public function testDeletingThePersonKeepsTheAccount(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AccountService($this->db);
        $personId = $this->insertBeneficiary($this->insertPayer());
        $account = $service->create($admin, $this->data(['beneficiary_id' => $personId]));

        $this->db->exec("DELETE FROM beneficiaries WHERE id = {$personId}");

        $this->assertNull($service->get($account['id'])['beneficiary_id']);
    }
}
