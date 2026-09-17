<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MigrationRunner;
use App\Migrations\AccountsAndOwnershipMigration;
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
