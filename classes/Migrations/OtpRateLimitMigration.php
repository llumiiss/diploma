<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 6 — limit wysyłek kodów logowania liczony w bazie zamiast w sesji (§5 pkt 9).
 *
 * Każda prośba o kod zapisuje wiersz w login_otps, więc limit per adres e-mail wystarczy policzyć
 * z historii. Adres IP pozwala dodatkowo ograniczyć maszynę, która próbuje wielu adresów naraz.
 */
final class OtpRateLimitMigration
{
    public static function up(PDO $db): void
    {
        if (!SchemaInspector::columnExists($db, 'login_otps', 'request_ip')) {
            $db->exec('ALTER TABLE login_otps ADD COLUMN request_ip VARCHAR(45) NULL AFTER email');
        }

        if (!SchemaInspector::indexExists($db, 'login_otps', 'idx_login_otps_email_created')) {
            $db->exec('ALTER TABLE login_otps ADD INDEX idx_login_otps_email_created (email, created_at)');
        }

        if (!SchemaInspector::indexExists($db, 'login_otps', 'idx_login_otps_ip_created')) {
            $db->exec('ALTER TABLE login_otps ADD INDEX idx_login_otps_ip_created (request_ip, created_at)');
        }
    }
}
