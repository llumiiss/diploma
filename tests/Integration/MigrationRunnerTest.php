<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MigrationRunner;
use App\Migrations\AccountsAndOwnershipMigration;
use App\Migrations\CertificateDiscountMigration;
use App\Migrations\CertificatesModelMigration;
use App\Migrations\SchemaInspector;
use Tests\Support\IntegrationTestCase;

final class MigrationRunnerTest extends IntegrationTestCase
{
    public function testFreshSchemaHasNoPendingMigrations(): void
    {
        $runner = new MigrationRunner($this->db);

        $this->assertSame([], $runner->runPending());
        $this->assertSame([], $runner->status());
        $this->assertTrue(SchemaInspector::tableExists($this->db, 'login_otps'));
    }

    public function testCertificatesModelIsInPlace(): void
    {
        $tables = [
            'certificates', 'beneficiaries', 'renewal_tasks', 'email_templates', 'attachments',
            'email_template_attachments', 'invitations', 'invitation_attachments', 'events',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(SchemaInspector::tableExists($this->db, $table), "Brak tabeli {$table}");
        }

        $this->assertFalse(SchemaInspector::tableExists($this->db, 'subscriptions'));
        $this->assertTrue(SchemaInspector::columnExists($this->db, 'certificates', 'certificate_type'));
        $this->assertFalse(SchemaInspector::columnExists($this->db, 'certificates', 'subscription_type'));
        $this->assertTrue(SchemaInspector::columnExists($this->db, 'certificates', 'archived_at'));
        $this->assertTrue(SchemaInspector::foreignKeyExists($this->db, 'certificates', 'fk_certificates_beneficiary'));
        $this->assertFalse(SchemaInspector::foreignKeyExists($this->db, 'certificates', 'fk_subscriptions_user'));

        $templates = (int) $this->db->query(
            "SELECT COUNT(*) FROM email_templates WHERE code IN ('renewal_invitation', 'renewal_reminder')"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $templates);
    }

    public function testDiscountMigrationReplacesPriceAndIsRepeatable(): void
    {
        $this->assertTrue(SchemaInspector::columnExists($this->db, 'certificates', 'discount_percent'));
        $this->assertFalse(SchemaInspector::columnExists($this->db, 'certificates', 'annual_cost'));
        $this->assertFalse(SchemaInspector::columnExists($this->db, 'certificates', 'currency'));
        $this->assertTrue(SchemaInspector::checkConstraintExists($this->db, 'certificates', 'chk_certificates_discount'));

        // Stan sprzed migracji: kolumny z kwotą i walutą, bez rabatu.
        $this->db->exec('ALTER TABLE certificates DROP CONSTRAINT chk_certificates_discount');
        $this->db->exec('ALTER TABLE certificates DROP COLUMN discount_percent');
        $this->db->exec('ALTER TABLE certificates ADD COLUMN annual_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00');
        $this->db->exec("ALTER TABLE certificates ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'PLN'");

        CertificateDiscountMigration::up($this->db);
        CertificateDiscountMigration::up($this->db);

        $this->assertTrue(SchemaInspector::columnExists($this->db, 'certificates', 'discount_percent'));
        $this->assertFalse(SchemaInspector::columnExists($this->db, 'certificates', 'annual_cost'));
        $this->assertFalse(SchemaInspector::columnExists($this->db, 'certificates', 'currency'));
        $this->assertTrue(SchemaInspector::checkConstraintExists($this->db, 'certificates', 'chk_certificates_discount'));
    }

    public function testDatabaseRejectsDiscountOutsideRange(): void
    {
        $admin = $this->createActor(\App\Rbac::ADMIN);
        $payerId = $this->insertPayer();

        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO certificates (name, certificate_type, expiry_date, user_id, payer_id, discount_percent)
            VALUES ('Za duży rabat', 'DOMAIN', '2030-01-01', {$admin->id}, {$payerId}, 150)");
    }

    public function testAccountsAndOwnershipMigrationUpgradesEtap1Schema(): void
    {
        // Cofnięcie schematu do stanu po Etapie 1, a potem migracja w przód.
        foreach (['payers' => 'fk_payers_created_by', 'beneficiaries' => 'fk_beneficiaries_created_by'] as $table => $fk) {
            $this->db->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$fk}");
            $index = $table === 'payers' ? 'idx_payers_created_by' : 'idx_beneficiaries_created_by';
            $this->db->exec("ALTER TABLE {$table} DROP INDEX {$index}");
            $this->db->exec("ALTER TABLE {$table} DROP COLUMN created_by_user_id");
        }
        $this->db->exec('ALTER TABLE users DROP COLUMN deactivated_at');

        AccountsAndOwnershipMigration::up($this->db);
        // Drugi przebieg niczego nie zmienia.
        AccountsAndOwnershipMigration::up($this->db);

        $this->assertTrue(SchemaInspector::columnExists($this->db, 'users', 'deactivated_at'));
        $this->assertTrue(SchemaInspector::columnExists($this->db, 'payers', 'created_by_user_id'));
        $this->assertTrue(SchemaInspector::foreignKeyExists($this->db, 'payers', 'fk_payers_created_by'));
        $this->assertTrue(SchemaInspector::columnExists($this->db, 'beneficiaries', 'created_by_user_id'));
        $this->assertTrue(SchemaInspector::foreignKeyExists($this->db, 'beneficiaries', 'fk_beneficiaries_created_by'));
    }

    public function testRepeatedModelMigrationDoesNotDuplicateTemplates(): void
    {
        $before = (int) $this->db->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();

        CertificatesModelMigration::up($this->db);

        $this->assertSame($before, (int) $this->db->query('SELECT COUNT(*) FROM email_templates')->fetchColumn());
    }
}
