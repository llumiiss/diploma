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
                email TEXT
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
}
