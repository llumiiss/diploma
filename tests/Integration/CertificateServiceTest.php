<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\CertificateService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class CertificateServiceTest extends IntegrationTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validData(int $payerId, array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Certyfikat kwalifikowany — Jan Kowalski',
            'certificate_type' => 'QUALIFIED_SIGNATURE',
            'serial_number'    => '5A3F9C21B7E04D18',
            'issuer'           => 'Certum QCA 2017',
            'valid_from'       => '2026-01-10',
            'expiry_date'      => '2028-01-10',
            'renewal_lead_days' => '30',
            'payer_id'         => $payerId,
            'discount_percent' => '-5%',
            'billing_cycle'    => 'multi_year',
            'payment_status'   => 'paid',
        ], $overrides);
    }

    public function testOperatorCreatesCertificateOwnedByThemselvesWithPayerFromBeneficiary(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer([], $operator->id);
        $beneficiaryId = $this->insertBeneficiary($payerId, [], $operator->id);
        $service = new CertificateService($this->db);

        $certificate = $service->create($operator, $this->validData($payerId, [
            'payer_id'       => '',
            'beneficiary_id' => $beneficiaryId,
            'user_id'        => $manager->id,
        ]));

        $this->assertSame($operator->id, $certificate['user_id']);
        $this->assertSame($payerId, $certificate['payer_id']);
        $this->assertSame(5.0, $certificate['discount_percent']);
        $this->assertSame('-5%', $certificate['discount_label']);
        $this->assertSame(30, $certificate['renewal_lead_days']);
        $this->assertSame(1, $this->countEvents('certificate', $certificate['id'], 'created'));
        $this->assertSame('created', $certificate['events'][0]['event_type']);
    }

    public function testManagerAssignsOwnerButNotAnInactiveAccount(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $inactive = $this->createActor(Rbac::OPERATOR);
        $this->db->exec("UPDATE users SET deactivated_at = NOW() WHERE id = {$inactive->id}");
        $payerId = $this->insertPayer();
        $service = new CertificateService($this->db);

        $certificate = $service->create($manager, $this->validData($payerId, ['user_id' => $operator->id]));
        $this->assertSame($operator->id, $certificate['user_id']);

        try {
            $service->create($manager, $this->validData($payerId, ['user_id' => $inactive->id, 'serial_number' => 'OTHER']));
            $this->fail('Nieaktywne konto nie może zostać opiekunem certyfikatu.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('user_id', $e->errors);
        }
    }

    public function testImpossibleDatesAndWrongOrderAreRejected(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer([], $operator->id);
        $service = new CertificateService($this->db);

        try {
            $service->create($operator, $this->validData($payerId, ['expiry_date' => '2026-02-31']));
            $this->fail('Data 2026-02-31 nie istnieje.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('expiry_date', $e->errors);
        }

        try {
            $service->create($operator, $this->validData($payerId, ['valid_from' => '2029-01-01']));
            $this->fail('Data „ważny od” nie może być późniejsza niż wygaśnięcie.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('valid_from', $e->errors);
        }
    }

    public function testSerialNumberIsUniquePerIssuer(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $payerId = $this->insertPayer();
        $service = new CertificateService($this->db);
        $first = $service->create($manager, $this->validData($payerId));

        try {
            $service->create($manager, $this->validData($payerId, ['name' => 'Duplikat']));
            $this->fail('Ten sam numer seryjny u tego samego wystawcy.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame($first['id'], $e->details['existing']['id']);
        }

        $other = $service->create($manager, $this->validData($payerId, ['issuer' => 'KIR']));
        $this->assertNotSame($first['id'], $other['id']);
    }

    public function testOperatorSeesOwnAndAssignedCertificatesOnly(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $managers = $this->insertCertificate($manager->id, $payerId, ['name' => 'Menedżera']);
        $assigned = $this->insertCertificate($manager->id, $payerId, ['name' => 'Przydzielony']);
        $own = $this->insertCertificate($operator->id, $payerId, ['name' => 'Własny']);
        $this->db->exec(
            "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date)
             VALUES ({$assigned}, {$operator->id}, 'todo', 'warning', CURDATE())"
        );
        $service = new CertificateService($this->db);

        $ids = array_column($service->list($operator), 'id');
        sort($ids);
        $this->assertSame([$assigned, $own], $ids);
        $this->assertCount(3, $service->list($manager));

        try {
            $service->update($operator, $managers, $this->validData($payerId));
            $this->fail('Operator nie może edytować cudzego certyfikatu.');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->httpStatus());
        }
    }

    public function testArchiveClosesOpenTaskAndRestoreRequiresActivePayer(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $certificateId = $this->insertCertificate($manager->id, $payerId);
        $this->db->exec(
            "INSERT INTO renewal_tasks (certificate_id, status, priority, due_date) VALUES ({$certificateId}, 'in_progress', 'critical', CURDATE())"
        );
        $service = new CertificateService($this->db);

        try {
            $service->archive($operator, $certificateId);
            $this->fail('Operator nie archiwizuje certyfikatów.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        $archived = $service->archive($manager, $certificateId);
        $this->assertNotNull($archived['archived_at']);
        $this->assertSame('abandoned', $this->db->query("SELECT status FROM renewal_tasks WHERE certificate_id = {$certificateId}")->fetchColumn());
        $this->assertSame([], $service->list($manager));

        $this->db->exec("UPDATE payers SET archived_at = NOW() WHERE id = {$payerId}");
        try {
            $service->restore($manager, $certificateId);
            $this->fail('Nie da się przywrócić certyfikatu płatnika z archiwum.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $this->db->exec("UPDATE payers SET archived_at = NULL WHERE id = {$payerId}");
        $this->assertNull($service->restore($manager, $certificateId)['archived_at']);
    }

    public function testUpdateRecordsOnlyChangedFields(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer([], $operator->id);
        $service = new CertificateService($this->db);
        $certificate = $service->create($operator, $this->validData($payerId));

        $updated = $service->update($operator, $certificate['id'], $this->validData($payerId, [
            'expiry_date' => '2028-03-01',
            'discount_percent' => '-5',
        ]));

        $this->assertSame('2028-03-01', $updated['expiry_date']);
        $updateEvent = array_values(array_filter($updated['events'], static fn (array $e): bool => $e['event_type'] === 'updated'));
        $this->assertCount(1, $updateEvent);
        $this->assertSame(['expiry_date'], array_keys($updateEvent[0]['payload']['changes']));
    }
}
