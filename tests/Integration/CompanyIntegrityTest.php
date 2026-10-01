<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migrations\CompanyIntegrityMigration;
use App\Migrations\SchemaInspector;
use App\Rbac;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\ServiceException;
use PDOException;
use Tests\Support\IntegrationTestCase;

/**
 * Relacja firma → użytkownik certyfikatu → certyfikat kwalifikowany (Etap 10):
 * pilnują jej i usługi (czytelny błąd w formularzu), i baza (ostatnia linia obrony).
 */
final class CompanyIntegrityTest extends IntegrationTestCase
{
    public function testServiceRequiresCompanyForEveryBeneficiary(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);

        try {
            $service->create($manager, ['first_name' => 'Jan', 'last_name' => 'Bez Firmy']);
            $this->fail('Użytkownik certyfikatu bez firmy nie może powstać.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertArrayHasKey('payer_id', $e->errors);
        }

        $created = $service->create($manager, ['first_name' => 'Jan', 'last_name' => 'Z Firmą', 'payer_id' => $this->insertPayer()]);
        $this->assertNotNull($created['payer_id']);

        $this->expectException(ServiceException::class);
        $service->update($manager, $created['id'], ['first_name' => 'Jan', 'last_name' => 'Z Firmą', 'payer_id' => '']);
    }

    public function testServiceRequiresBeneficiaryForQualifiedCertificatesOnly(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $payerId = $this->insertPayer();
        $personId = $this->insertBeneficiary($payerId);
        $service = new CertificateService($this->db);
        $base = ['name' => 'Certyfikat', 'expiry_date' => '2030-01-01', 'payer_id' => $payerId];

        foreach (['QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL'] as $type) {
            try {
                $service->create($manager, $base + ['certificate_type' => $type]);
                $this->fail("{$type} bez użytkownika nie może powstać.");
            } catch (ServiceException $e) {
                $this->assertArrayHasKey('beneficiary_id', $e->errors, $type);
            }
        }

        $ssl = $service->create($manager, $base + ['certificate_type' => 'SSL_CERTIFICATE']);
        $this->assertNull($ssl['beneficiary_id']);

        $signature = $service->create($manager, $base + ['certificate_type' => 'QUALIFIED_SIGNATURE', 'beneficiary_id' => $personId]);
        $this->assertSame($personId, $signature['beneficiary_id']);

        // Zmiana typu istniejącego certyfikatu na kwalifikowany też wymaga użytkownika.
        $this->expectException(ServiceException::class);
        $service->update($manager, $ssl['id'], $base + ['certificate_type' => 'QUALIFIED_SEAL']);
    }

    public function testDatabaseRejectsBeneficiaryWithoutCompany(): void
    {
        $this->expectException(PDOException::class);
        $this->db->exec("INSERT INTO beneficiaries (first_name, last_name, payer_id) VALUES ('Jan', 'Sierota', NULL)");
    }

    public function testDatabaseRejectsQualifiedCertificateWithoutUser(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $payerId = $this->insertPayer();

        $this->expectException(PDOException::class);
        $this->db->exec(
            "INSERT INTO certificates (name, certificate_type, expiry_date, user_id, payer_id)
             VALUES ('Podpis bez osoby', 'QUALIFIED_SIGNATURE', '2030-01-01', {$admin->id}, {$payerId})"
        );
    }

    public function testDatabaseRejectsTurningCertificateIntoQualifiedWithoutUser(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $certificateId = $this->insertCertificate($admin->id, $this->insertPayer(), ['certificate_type' => 'SSL_CERTIFICATE']);

        $this->expectException(PDOException::class);
        $this->db->exec("UPDATE certificates SET certificate_type = 'QUALIFIED_SEAL' WHERE id = {$certificateId}");
    }

    public function testCompanyWithPeopleAndPersonWithCertificatesCannotBeDeleted(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $payerId = $this->insertPayer();
        $personId = $this->insertBeneficiary($payerId);
        $this->insertCertificate($admin->id, $payerId, ['beneficiary_id' => $personId]);

        foreach (["DELETE FROM beneficiaries WHERE id = {$personId}", "DELETE FROM payers WHERE id = {$payerId}"] as $sql) {
            try {
                $this->db->exec($sql);
                $this->fail("Usunięcie powinno zablokować powiązanie: {$sql}");
            } catch (PDOException $e) {
                $this->assertSame(23000, (int) $e->getCode());
            }
        }
    }

    public function testMigrationRepairsLegacyRecordsAndTightensSchema(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $payerId = $this->insertPayer(['company_name' => 'Firma Starego Certyfikatu']);

        // Stan sprzed migracji: osoby mogą nie mieć firmy, a certyfikaty kwalifikowane — osoby.
        $this->db->exec('ALTER TABLE certificates DROP CONSTRAINT chk_certificates_qualified_user');
        $this->db->exec('ALTER TABLE certificates DROP FOREIGN KEY fk_certificates_beneficiary');
        $this->db->exec(
            'ALTER TABLE certificates ADD CONSTRAINT fk_certificates_beneficiary FOREIGN KEY (beneficiary_id)
             REFERENCES beneficiaries(id) ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        $this->db->exec('ALTER TABLE beneficiaries MODIFY COLUMN payer_id INT UNSIGNED NULL');

        $this->db->exec("INSERT INTO beneficiaries (first_name, last_name, payer_id) VALUES ('Ola', 'Bez Certyfikatu', NULL)");
        $withoutCertificate = (int) $this->db->lastInsertId();
        $this->db->exec("INSERT INTO beneficiaries (first_name, last_name, payer_id) VALUES ('Ala', 'Z Certyfikatem', NULL)");
        $withCertificate = (int) $this->db->lastInsertId();
        $this->db->exec(
            "INSERT INTO certificates (name, certificate_type, expiry_date, user_id, payer_id, beneficiary_id)
             VALUES ('Dla Ali', 'SSL_CERTIFICATE', '2030-01-01', {$admin->id}, {$payerId}, {$withCertificate})"
        );
        $this->db->exec(
            "INSERT INTO certificates (name, certificate_type, expiry_date, user_id, payer_id)
             VALUES ('Podpis sierota', 'QUALIFIED_SIGNATURE', '2030-01-01', {$admin->id}, {$payerId})"
        );
        $orphanCertificate = (int) $this->db->lastInsertId();

        CompanyIntegrityMigration::up($this->db);
        CompanyIntegrityMigration::up($this->db);

        // Osoba z certyfikatem dziedziczy firmę certyfikatu, osoba bez niego dostaje firmę zastępczą.
        $payers = $this->db->query('SELECT id, payer_id FROM beneficiaries')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame($payerId, (int) $payers[$withCertificate]);
        $placeholder = (int) $this->db->query(
            "SELECT id FROM payers WHERE company_name = '" . CompanyIntegrityMigration::PLACEHOLDER_COMPANY . "'"
        )->fetchColumn();
        $this->assertSame($placeholder, (int) $payers[$withoutCertificate]);

        // Certyfikat kwalifikowany bez osoby dostaje osobę z firmą certyfikatu.
        $person = $this->db->query(
            "SELECT b.last_name, b.payer_id FROM certificates c JOIN beneficiaries b ON b.id = c.beneficiary_id WHERE c.id = {$orphanCertificate}"
        )->fetch();
        $this->assertSame('do uzupełnienia', $person['last_name']);
        $this->assertSame($payerId, (int) $person['payer_id']);

        $this->assertFalse(SchemaInspector::columnIsNullable($this->db, 'beneficiaries', 'payer_id'));
        $this->assertSame('RESTRICT', SchemaInspector::foreignKeyUpdateRule($this->db, 'certificates', 'fk_certificates_beneficiary'));
        $this->assertTrue(SchemaInspector::checkConstraintExists($this->db, 'certificates', 'chk_certificates_qualified_user'));
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM payers WHERE company_name = '" . CompanyIntegrityMigration::PLACEHOLDER_COMPANY . "'"
        )->fetchColumn());
    }
}
