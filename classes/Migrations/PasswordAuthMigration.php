<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 9: logowanie hasłem z weryfikacją adresu e-mail.
 *
 * Do tej pory logowanie było bezhasłowe — jednorazowy kod z e-maila (tabela login_otps).
 * Nowy schemat to e-mail + hasło, a adres potwierdza się jednorazowym linkiem:
 *
 *   - users.password_hash      — hasło liczone przez password_hash() (bcrypt/argon zależnie od PHP),
 *                                NULL = konto jeszcze bez hasła (założone przez administratora),
 *   - users.email_verified_at  — data potwierdzenia adresu; NULL = konto nieaktywne, nie można się zalogować,
 *   - users.last_login_at      — ostatnie udane logowanie (wcześniej liczone z login_otps),
 *   - email_verifications      — jednorazowe tokeny: potwierdzenie adresu i ustawienie/reset hasła,
 *   - login_attempts           — historia prób logowania na potrzeby limitu (ochrona przed zgadywaniem haseł).
 *
 * Konta istniejące przed migracją traktujemy jako potwierdzone: ich właściciele odbierali
 * kody logowania na ten adres, czyli adres jest sprawdzony. Hasła nie mają — ustawiają je
 * linkiem z wiadomości „ustaw hasło” (scripts/invite-user.php) albo poleceniem scripts/set-password.php.
 *
 * Tabeli login_otps migracja nie usuwa: zostaje jako historia logowań sprzed zmiany.
 * Migracja jest idempotentna — każdy krok sprawdza, czy jest jeszcze co robić.
 */
final class PasswordAuthMigration
{
    public static function up(PDO $db): void
    {
        self::extendUsers($db);
        self::createEmailVerifications($db);
        self::createLoginAttempts($db);
        self::markExistingAccountsVerified($db);
        self::backfillLastLogin($db);
    }

    private static function extendUsers(PDO $db): void
    {
        $columns = [
            'password_hash'     => 'ADD COLUMN password_hash VARCHAR(255) NULL AFTER email',
            'email_verified_at' => 'ADD COLUMN email_verified_at DATETIME NULL AFTER password_hash',
            'last_login_at'     => 'ADD COLUMN last_login_at DATETIME NULL AFTER email_verified_at',
        ];

        foreach ($columns as $column => $clause) {
            if (!SchemaInspector::columnExists($db, 'users', $column)) {
                $db->exec('ALTER TABLE users ' . $clause);
            }
        }

        if (!SchemaInspector::indexExists($db, 'users', 'idx_users_verified')) {
            $db->exec('ALTER TABLE users ADD INDEX idx_users_verified (email_verified_at)');
        }
    }

    private static function createEmailVerifications(PDO $db): void
    {
        if (SchemaInspector::tableExists($db, 'email_verifications')) {
            return;
        }

        $db->exec(
            "CREATE TABLE email_verifications (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                email      VARCHAR(255) NOT NULL,
                purpose    ENUM('EMAIL_VERIFY', 'PASSWORD_SET') NOT NULL DEFAULT 'EMAIL_VERIFY',
                token_hash CHAR(64) NOT NULL,
                request_ip VARCHAR(45) NULL,
                expires_at DATETIME NOT NULL,
                used_at    DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_email_verifications_token (token_hash),
                INDEX idx_email_verifications_email_created (email, created_at),
                INDEX idx_email_verifications_ip_created (request_ip, created_at),
                CONSTRAINT fk_email_verifications_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function createLoginAttempts(PDO $db): void
    {
        if (SchemaInspector::tableExists($db, 'login_attempts')) {
            return;
        }

        $db->exec(
            'CREATE TABLE login_attempts (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(255) NOT NULL,
                request_ip VARCHAR(45) NULL,
                successful TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_login_attempts_email_created (email, created_at),
                INDEX idx_login_attempts_ip_created (request_ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Konta sprzed migracji potwierdzały adres kodami logowania — zostają potwierdzone,
     * żeby zmiana nie odcięła nikogo od systemu. Hasło ustawiają osobnym linkiem.
     */
    private static function markExistingAccountsVerified(PDO $db): void
    {
        $db->exec(
            'UPDATE users
                SET email_verified_at = COALESCE(created_at, NOW())
              WHERE email_verified_at IS NULL
                AND email IS NOT NULL'
        );
    }

    private static function backfillLastLogin(PDO $db): void
    {
        if (!SchemaInspector::tableExists($db, 'login_otps')) {
            return;
        }

        $db->exec(
            'UPDATE users u
                SET u.last_login_at = (
                    SELECT MAX(o.used_at) FROM login_otps o WHERE o.user_id = u.id
                )
              WHERE u.last_login_at IS NULL'
        );
    }
}
