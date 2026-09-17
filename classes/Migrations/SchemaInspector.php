<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Pytania o bieżący kształt schematu (information_schema) — podstawa idempotentnych migracji.
 *
 * Metody są oznaczone jako nieczyste: wynik zależy od stanu bazy, który migracja właśnie zmienia,
 * więc analiza statyczna nie może zapamiętywać ich wyników między wywołaniami.
 */
final class SchemaInspector
{
    /** @phpstan-impure */
    public static function tableExists(PDO $db, string $table): bool
    {
        return self::exists(
            $db,
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1',
            ['table' => $table]
        );
    }

    /** @phpstan-impure */
    public static function columnExists(PDO $db, string $table, string $column): bool
    {
        return self::exists(
            $db,
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1',
            ['table' => $table, 'column' => $column]
        );
    }

    /** @phpstan-impure */
    public static function indexExists(PDO $db, string $table, string $index): bool
    {
        return self::exists(
            $db,
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index LIMIT 1',
            ['table' => $table, 'index' => $index]
        );
    }

    /** @phpstan-impure */
    public static function foreignKeyExists(PDO $db, string $table, string $constraint): bool
    {
        return self::exists(
            $db,
            "SELECT 1 FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = :table
               AND constraint_name = :constraint AND constraint_type = 'FOREIGN KEY' LIMIT 1",
            ['table' => $table, 'constraint' => $constraint]
        );
    }

    /**
     * @param array<string, string> $params
     */
    private static function exists(PDO $db, string $sql, array $params): bool
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $found;
    }
}
