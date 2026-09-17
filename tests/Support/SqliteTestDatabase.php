<?php

declare(strict_types=1);

namespace Tests\Support;

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
                email TEXT,
                deactivated_at TEXT NULL
            )'
        );

        $db->exec(
            'CREATE TABLE login_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                code_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec(
            'CREATE TABLE certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                payer_id INTEGER NOT NULL DEFAULT 1,
                name TEXT NOT NULL,
                scope TEXT NOT NULL DEFAULT "corporate",
                expiry_date TEXT NOT NULL
            )'
        );

        return $db;
    }

    public static function seedUser(PDO $db, string $email, string $first = 'Test', string $last = 'User'): int
    {
        $stmt = $db->prepare(
            'INSERT INTO users (first_name, last_name, role, email) VALUES (:first, :last, "OPERATOR", :email)'
        );
        $stmt->execute(['first' => $first, 'last' => $last, 'email' => $email]);

        return (int) $db->lastInsertId();
    }

    public static function seedCertificate(PDO $db, int $userId, string $name = 'SSL Wildcard'): int
    {
        $stmt = $db->prepare(
            'INSERT INTO certificates (user_id, payer_id, name, scope, expiry_date)
             VALUES (:user_id, 1, :name, "corporate", "2030-01-01")'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);

        return (int) $db->lastInsertId();
    }
}
