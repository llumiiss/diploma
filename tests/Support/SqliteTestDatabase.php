<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\PasswordPolicy;
use PDO;

final class SqliteTestDatabase
{
    public static function create(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "OPERATOR",
                beneficiary_id INTEGER NULL,
                company_filter TEXT NULL,
                email TEXT,
                password_hash TEXT NULL,
                email_verified_at TEXT NULL,
                last_login_at TEXT NULL,
                deactivated_at TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec(
            'CREATE TABLE login_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                request_ip TEXT NULL,
                code_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec(
            'CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT "EMAIL_VERIFY",
                token_hash TEXT NOT NULL UNIQUE,
                request_ip TEXT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $db->exec(
            'CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                request_ip TEXT NULL,
                successful INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );

        $db->exec(
            'CREATE TABLE certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                payer_id INTEGER NOT NULL DEFAULT 1,
                name TEXT NOT NULL,
                expiry_date TEXT NOT NULL
            )'
        );

        return $db;
    }

    /**
     * Konto gotowe do logowania: hasło ustawione, adres potwierdzony.
     * $verified = false daje konto po rejestracji, które czeka na potwierdzenie adresu.
     */
    public static function seedUser(
        PDO $db,
        string $email,
        string $first = 'Test',
        string $last = 'User',
        string $password = 'TajneHaslo123',
        bool $verified = true
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO users (first_name, last_name, role, email, password_hash, email_verified_at, created_at)
             VALUES (:first, :last, "OPERATOR", :email, :password_hash, :verified_at, :created_at)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'first'         => $first,
            'last'          => $last,
            'email'         => $email,
            'password_hash' => $password === '' ? null : PasswordPolicy::hash($password),
            'verified_at'   => $verified ? $now : null,
            'created_at'    => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function seedCertificate(PDO $db, int $userId, string $name = 'SSL Wildcard'): int
    {
        $stmt = $db->prepare(
            'INSERT INTO certificates (user_id, payer_id, name, expiry_date)
             VALUES (:user_id, 1, :name, "2030-01-01")'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);

        return (int) $db->lastInsertId();
    }
}
