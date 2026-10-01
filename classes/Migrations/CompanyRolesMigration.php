<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: role stanowisk w firmie i powiązanie konta z użytkownikiem certyfikatu.
 *
 *   - users.role: do ADMIN, MANAGER i OPERATOR dochodzą DIRECTOR (szef), ACCOUNTANT (księgowa),
 *     IT (informatyk) i EMPLOYEE (pracownik) — opis ról w App\Rbac,
 *   - users.beneficiary_id: konto pracownika może wskazywać użytkownika certyfikatu, którym jest ta osoba;
 *     jej certyfikaty są wtedy „własne” (zakres personal). Usunięcie osoby zeruje powiązanie.
 *
 * Istniejące konta zachowują swoje role. Migracja jest idempotentna.
 */
final class CompanyRolesMigration
{
    /** Kolejność jak w App\Rbac::roles(). */
    public const ROLES = ['ADMIN', 'DIRECTOR', 'MANAGER', 'ACCOUNTANT', 'IT', 'OPERATOR', 'EMPLOYEE'];

    public static function up(PDO $db): void
    {
        $enum = "'" . implode("', '", self::ROLES) . "'";
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM({$enum}) NOT NULL DEFAULT 'OPERATOR'");

        if (!SchemaInspector::columnExists($db, 'users', 'beneficiary_id')) {
            $db->exec('ALTER TABLE users ADD COLUMN beneficiary_id INT UNSIGNED NULL AFTER role');
        }

        if (!SchemaInspector::indexExists($db, 'users', 'idx_users_beneficiary')) {
            $db->exec('ALTER TABLE users ADD INDEX idx_users_beneficiary (beneficiary_id)');
        }

        if (!SchemaInspector::foreignKeyExists($db, 'users', 'fk_users_beneficiary')) {
            $db->exec(
                'ALTER TABLE users ADD CONSTRAINT fk_users_beneficiary
                    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id)
                    ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }
    }
}
