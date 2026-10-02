<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: filtr firm zapisany przy koncie.
 *
 * users.company_filter przechowuje wybór z panelu: {"mode": "one" | "list", "ids": [identyfikatory firm]}.
 * NULL oznacza wszystkie firmy (domyślnie). Filtr zawęża listy i wskaźniki, ale nie zmienia
 * uprawnień — widoczność rekordów nadal wynika z roli (App\Service\Visibility).
 *
 * Migracja jest idempotentna.
 */
final class CompanyFilterMigration
{
    public static function up(PDO $db): void
    {
        if (!SchemaInspector::columnExists($db, 'users', 'company_filter')) {
            $db->exec('ALTER TABLE users ADD COLUMN company_filter JSON NULL AFTER beneficiary_id');
        }
    }
}
