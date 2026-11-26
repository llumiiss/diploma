<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 11: szablony odczytu wniosków z poczty.
 *
 * reading_rules to własne etykiety dla ekstraktora: „w wiadomościach od tego nadawcy etykieta X oznacza pole Y”.
 * Dzięki nim nowy układ wiadomości obsługuje się importem pliku CSV/XML, bez zmiany kodu.
 *
 *   name          — nazwa szablonu (grupuje etykiety jednego układu wiadomości),
 *   field         — pole formularza wniosku (kod z RegistrationExtractor::readableFields()),
 *   label         — etykieta, po której w wiadomości stoi wartość,
 *   sender_match  — fragment adresu nadawcy, którego dotyczy reguła (NULL = każdy),
 *   subject_match — fragment tematu (NULL = każdy).
 *
 * Migracja jest idempotentna.
 */
final class ReadingRulesMigration
{
    public static function up(PDO $db): void
    {
        if (SchemaInspector::tableExists($db, 'reading_rules')) {
            return;
        }

        $db->exec(
            'CREATE TABLE reading_rules (
                id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name                VARCHAR(120) NOT NULL,
                field               VARCHAR(64) NOT NULL,
                label               VARCHAR(120) NOT NULL,
                sender_match        VARCHAR(255) NULL,
                subject_match       VARCHAR(255) NULL,
                is_active           TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id  INT UNSIGNED NULL,
                created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reading_rules (name, field, label),
                KEY idx_reading_rules_created_by (created_by_user_id),
                CONSTRAINT fk_reading_rules_created_by
                    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
