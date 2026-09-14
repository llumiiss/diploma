<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class MigrationRunner
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * @return list<string> Names of migrations that were applied in this run.
     */
    public function runPending(): array
    {
        $this->ensureMigrationsTable();

        $applied = [];
        foreach ($this->migrations() as $name => $callback) {
            if ($this->isApplied($name)) {
                continue;
            }

            $callback($this->db);
            $this->markApplied($name);
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return list<string>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $pending = [];
        foreach (array_keys($this->migrations()) as $name) {
            if (!$this->isApplied($name)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function isApplied(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    private function markApplied(string $name): void
    {
        $stmt = $this->db->prepare('INSERT INTO schema_migrations (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
    }

    /**
     * @return array<string, callable(PDO): void>
     */
    private function migrations(): array
    {
        return [
            'login_otp' => static function (PDO $db): void {
                if (!self::indexExists($db, 'users', 'uq_users_email')) {
                    try {
                        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
                    } catch (PDOException $e) {
                        if (!self::indexExists($db, 'users', 'uq_users_email')) {
                            throw $e;
                        }
                    }
                }

                if (!self::tableExists($db, 'login_otps')) {
                    $db->exec(
                        'CREATE TABLE login_otps (
                            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id    INT UNSIGNED NOT NULL,
                            email      VARCHAR(255) NOT NULL,
                            code_hash  VARCHAR(255) NOT NULL,
                            expires_at DATETIME NOT NULL,
                            used_at    DATETIME NULL,
                            attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            CONSTRAINT fk_login_otps_user
                                FOREIGN KEY (user_id) REFERENCES users(id)
                                ON DELETE CASCADE ON UPDATE CASCADE,
                            INDEX idx_login_otps_email_expires (email, expires_at)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                    );
                } elseif (!self::columnExists($db, 'login_otps', 'attempt_count')) {
                    $db->exec(
                        'ALTER TABLE login_otps
                         ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER used_at'
                    );
                }
            },
            'manager_subskrypcji' => static function (PDO $db): void {
                if (!self::tableExists($db, 'manager_subskrypcji')) {
                    $db->exec(
                        'CREATE TABLE manager_subskrypcji (
                            id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id                   INT UNSIGNED NOT NULL,
                            nazwa_uslugi              VARCHAR(255) NOT NULL,
                            mail_subskrypcji          VARCHAR(255) NULL,
                            username_konta            VARCHAR(255) NULL,
                            koszt_pln                 DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                            data_nastepnej_platnosci  DATE NOT NULL,
                            created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            CONSTRAINT fk_manager_subskrypcji_user
                                FOREIGN KEY (user_id) REFERENCES users(id)
                                ON DELETE CASCADE ON UPDATE CASCADE,
                            INDEX idx_manager_sub_user (user_id),
                            INDEX idx_manager_sub_payment_date (data_nastepnej_platnosci)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                    );
                }
            },
        ];
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
        );
        $stmt->execute(['table' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return $stmt->fetchColumn() !== false;
    }

    private static function indexExists(PDO $db, string $table, string $index): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'index' => $index]);

        return $stmt->fetchColumn() !== false;
    }
}
