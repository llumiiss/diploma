<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\BeneficiaryService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class BeneficiaryServiceTest extends IntegrationTestCase
{
    public function testOperatorCanLinkOnlyPayersVisibleToThem(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new BeneficiaryService($this->db);
        $ownPayer = $this->insertPayer([], $operator->id);
        $hiddenPayer = $this->insertPayer([], $manager->id);

        $beneficiary = $service->create($operator, ['first_name' => 'Maria', 'last_name' => 'Wójcik', 'payer_id' => $ownPayer]);
        $this->assertSame($ownPayer, $beneficiary['payer_id']);
        $this->assertSame(1, $this->countEvents('beneficiary', $beneficiary['id'], 'created'));

        try {
            $service->create($operator, ['first_name' => 'Piotr', 'last_name' => 'Nowak', 'payer_id' => $hiddenPayer]);
            $this->fail('Operator nie może powiązać osoby z płatnikiem, którego nie widzi.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('payer_id', $e->errors);
        }
    }

    public function testBeneficiaryLinkedToOperatorsCertificateIsVisible(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new BeneficiaryService($this->db);
        $payerId = $this->insertPayer([], $manager->id);
        $linked = $this->insertBeneficiary($payerId, ['first_name' => 'Jan'], $manager->id);
        $this->insertBeneficiary($payerId, ['first_name' => 'Ukryty'], $manager->id);
        $this->insertCertificate($operator->id, $payerId, ['beneficiary_id' => $linked]);

        $this->assertSame([$linked], array_column($service->list($operator), 'id'));
        $this->assertCount(2, $service->list($manager));
    }

    public function testArchiveIsBlockedByActiveCertificateAndRestoreNeedsActivePayer(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $payerId = $this->insertPayer();
        $beneficiaryId = $this->insertBeneficiary($payerId);
        $certificateId = $this->insertCertificate($manager->id, $payerId, ['beneficiary_id' => $beneficiaryId]);

        try {
            $service->archive($manager, $beneficiaryId);
            $this->fail('Osoba z aktywnym certyfikatem nie może trafić do archiwum.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $this->db->exec("UPDATE certificates SET archived_at = NOW() WHERE id = {$certificateId}");
        $service->archive($manager, $beneficiaryId);
        $this->db->exec("UPDATE payers SET archived_at = NOW() WHERE id = {$payerId}");

        try {
            $service->restore($manager, $beneficiaryId);
            $this->fail('Przywrócenie osoby wymaga aktywnego płatnika.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $this->db->exec("UPDATE payers SET archived_at = NULL WHERE id = {$payerId}");
        $this->assertNull($service->restore($manager, $beneficiaryId)['archived_at']);
    }

    public function testArchivedBeneficiaryIsReadOnly(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $beneficiaryId = $this->insertBeneficiary(null);
        $service->archive($manager, $beneficiaryId);

        $this->expectException(ServiceException::class);
        $service->update($manager, $beneficiaryId, ['first_name' => 'Nowe', 'last_name' => 'Imię']);
    }
}
