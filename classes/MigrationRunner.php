<?php

declare(strict_types=1);

namespace App;

use App\Migrations\AccountsAndOwnershipMigration;
use App\Migrations\CertificateDiscountMigration;
use App\Migrations\CertificatesModelMigration;
use App\Migrations\OtpRateLimitMigration;
use App\Migrations\PasswordAuthMigration;
use App\Migrations\RenewalProcessMigration;
use App\Migrations\SchemaInspector;
use App\Migrations\SplitPersonalAppMigration;
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
                if (!SchemaInspector::indexExists($db, 'users', 'uq_users_email')) {
                    try {
                        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
                    } catch (PDOException $e) {
                        if (!SchemaInspector::indexExists($db, 'users', 'uq_users_email')) {
                            throw $e;
                        }
                    }
                }

                if (!SchemaInspector::tableExists($db, 'login_otps')) {
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
                } elseif (!SchemaInspector::columnExists($db, 'login_otps', 'attempt_count')) {
                    $db->exec(
                        'ALTER TABLE login_otps
                         ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER used_at'
                    );
                }
            },
            // Etap 1: subscriptions → certificates + beneficjenci, zadania, szablony, załączniki, zaproszenia, historia.
            'certificates_model' => static function (PDO $db): void {
                CertificatesModelMigration::up($db);
            },
            // Etap 2: wyłączanie kont (D3) i autor rekordów osób i płatników (D8).
            'accounts_and_ownership' => static function (PDO $db): void {
                AccountsAndOwnershipMigration::up($db);
            },
            // Etap 3: ustawienia procesu odnowień (progi, przypomnienia).
            'renewal_process' => static function (PDO $db): void {
                RenewalProcessMigration::up($db);
            },
            // Etap 6: limit wysyłek kodów logowania liczony w bazie (§5 pkt 9).
            'otp_rate_limit' => static function (PDO $db): void {
                OtpRateLimitMigration::up($db);
            },
            // Etap 8: panel prywatny wyprowadzony do osobnej aplikacji — baza opisuje tylko ewidencję firmową.
            'split_personal_app' => static function (PDO $db): void {
                SplitPersonalAppMigration::up($db);
            },
            // Etap 9: logowanie hasłem z potwierdzeniem adresu e-mail (zamiast kodów jednorazowych).
            'password_auth' => static function (PDO $db): void {
                PasswordAuthMigration::up($db);
            },
            // Etap 10: rabat w procentach zamiast kwoty i waluty certyfikatu.
            'certificate_discount' => static function (PDO $db): void {
                CertificateDiscountMigration::up($db);
            },
        ];
    }
}
