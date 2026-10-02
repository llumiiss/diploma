<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: powiadomienia wewnętrzne między kontami (administrator, operatorzy, pozostałe role).
 *
 * Tabela notifications trzyma jedną wiadomość dla jednego odbiorcy (wiadomość do kilku osób to kilka
 * wierszy o wspólnym batch_key). Odpowiedzi tworzą wątek: thread_id wskazuje wiadomość początkową,
 * a parent_id — wiadomość, na którą odpowiedziano. Wiadomości systemowe nie mają nadawcy.
 *
 * Migracja jest idempotentna.
 */
final class NotificationsMigration
{
    public static function up(PDO $db): void
    {
        if (SchemaInspector::tableExists($db, 'notifications')) {
            return;
        }

        $db->exec(
            "CREATE TABLE notifications (
                id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_key         CHAR(16) NOT NULL,
                thread_id         BIGINT UNSIGNED NULL,
                parent_id         BIGINT UNSIGNED NULL,
                sender_user_id    INT UNSIGNED NULL,
                recipient_user_id INT UNSIGNED NOT NULL,
                type              ENUM('message', 'request', 'error', 'system') NOT NULL DEFAULT 'message',
                subject           VARCHAR(255) NOT NULL,
                body              TEXT NOT NULL,
                related_type      ENUM('certificate', 'beneficiary', 'payer', 'registration') NULL,
                related_id        INT UNSIGNED NULL,
                read_at           DATETIME NULL,
                resolved_at       DATETIME NULL,
                archived_at       DATETIME NULL,
                created_at        DATETIME NOT NULL,
                KEY idx_notifications_recipient (recipient_user_id, archived_at, read_at),
                KEY idx_notifications_recipient_created (recipient_user_id, created_at),
                KEY idx_notifications_sender (sender_user_id, created_at),
                KEY idx_notifications_thread (thread_id),
                KEY idx_notifications_batch (batch_key),
                KEY idx_notifications_related (related_type, related_id),
                CONSTRAINT fk_notifications_sender
                    FOREIGN KEY (sender_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_notifications_recipient
                    FOREIGN KEY (recipient_user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
