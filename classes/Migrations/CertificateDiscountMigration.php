<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: rabat zamiast ceny.
 *
 * Kolumna z kwotą (certificates.annual_cost) i waluta (certificates.currency) zostają zastąpione
 * jedną kolumną discount_percent — wielkością rabatu w procentach (0–100), np. 3.00 dla „-3%”.
 * Interfejs pokazuje ją z minusem, w bazie jest liczba nieujemna, więc średnie i porównania
 * nie zależą od znaku. Zakres pilnuje ograniczenie CHECK.
 *
 * Kwoty ze starej kolumny nie mają odpowiednika w rabacie i nie są przenoszone — przed zmianą
 * schematu warto zrobić zrzut bazy (mysqldump). Migracja jest idempotentna.
 */
final class CertificateDiscountMigration
{
    public static function up(PDO $db): void
    {
        if (!SchemaInspector::columnExists($db, 'certificates', 'discount_percent')) {
            $db->exec(
                'ALTER TABLE certificates
                 ADD COLUMN discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 0.00 AFTER status'
            );
        }

        if (!SchemaInspector::checkConstraintExists($db, 'certificates', 'chk_certificates_discount')) {
            $db->exec(
                'ALTER TABLE certificates
                 ADD CONSTRAINT chk_certificates_discount CHECK (discount_percent BETWEEN 0 AND 100)'
            );
        }

        foreach (['annual_cost', 'currency'] as $column) {
            if (SchemaInspector::columnExists($db, 'certificates', $column)) {
                $db->exec("ALTER TABLE certificates DROP COLUMN {$column}");
            }
        }
    }
}
