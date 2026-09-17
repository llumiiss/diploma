<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 2 — konta zakładane przez administratora (D3) i zakres danych operatora (D8).
 *
 *  - users.deactivated_at: konto wyłączone przez ADMIN-a nie może się zalogować,
 *    ale zostaje w bazie razem z historią i powiązanymi rekordami;
 *  - payers.created_by_user_id, beneficiaries.created_by_user_id: kto wprowadził rekord —
 *    OPERATOR widzi osoby i płatników, których sam dodał, zanim powiąże ich z certyfikatem.
 *
 * Idempotentna: każdy krok sprawdza bieżący kształt schematu.
 */
final class AccountsAndOwnershipMigration
{
    public static function up(PDO $db): void
    {
        if (!SchemaInspector::columnExists($db, 'users', 'deactivated_at')) {
            $db->exec('ALTER TABLE users ADD COLUMN deactivated_at DATETIME NULL AFTER email');
        }

        self::addCreatedBy($db, 'payers', 'city', 'idx_payers_created_by', 'fk_payers_created_by');
        self::addCreatedBy($db, 'beneficiaries', 'notes', 'idx_beneficiaries_created_by', 'fk_beneficiaries_created_by');
    }

    private static function addCreatedBy(PDO $db, string $table, string $after, string $index, string $foreignKey): void
    {
        if (!SchemaInspector::columnExists($db, $table, 'created_by_user_id')) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER {$after}");
        }

        if (!SchemaInspector::indexExists($db, $table, $index)) {
            $db->exec("ALTER TABLE {$table} ADD KEY {$index} (created_by_user_id)");
        }

        if (!SchemaInspector::foreignKeyExists($db, $table, $foreignKey)) {
            $db->exec(
                "ALTER TABLE {$table} ADD CONSTRAINT {$foreignKey}
                    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE"
            );
        }
    }
}
