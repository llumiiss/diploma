<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 3 — proces odnowień: tabela ustawień z progami odnowień i regułami przypomnień,
 * które administrator zmienia w panelu (docs/MAPA_PROJEKTU.md §2.3).
 *
 * Wartości domyślne są w kodzie (App\Settings::DEFAULTS) — tabela przechowuje tylko zmiany.
 */
final class RenewalProcessMigration
{
    public static function up(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                setting_key         VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value       VARCHAR(255) NOT NULL,
                updated_by_user_id  INT UNSIGNED NULL,
                updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_settings_user (updated_by_user_id),
                CONSTRAINT fk_settings_user
                    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
