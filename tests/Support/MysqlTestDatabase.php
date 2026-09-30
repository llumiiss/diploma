<?php

declare(strict_types=1);

namespace Tests\Support;

use App\MigrationRunner;
use App\Migrations\CertificatesModelMigration;
use PDO;

/**
 * Osobna baza MySQL dla testów integracyjnych — testy nigdy nie dotykają bazy użytkownika.
 *
 * Przy pierwszym użyciu w danym uruchomieniu PHPUnit baza jest tworzona od zera z
 * database/schema.sql, a potem przechodzą po niej migracje (tak jak `migrate.php --fresh`).
 * Przed każdym testem reset() czyści dane biznesowe.
 */
final class MysqlTestDatabase
{
    public const NAME = 'assistent_subscriptions_test';

    /** Tabele czyszczone przed każdym testem; domyślne szablony wiadomości są potem wstawiane od nowa. */
    private const BUSINESS_TABLES = [
        'settings', 'events', 'invitation_attachments', 'invitations', 'email_template_attachments', 'attachments',
        'email_templates', 'renewal_tasks', 'certificates', 'beneficiaries',
        'login_attempts', 'email_verifications', 'login_otps',
        'payers', 'users',
    ];

    private static ?PDO $connection = null;

    public static function isEnabled(): bool
    {
        return (bool) getenv('RUN_INTEGRATION_TESTS');
    }

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $server = new PDO(
            sprintf('mysql:host=%s;charset=%s', $config['host'], $config['charset']),
            $config['username'],
            $config['password'],
            $options
        );
        $server->exec('DROP DATABASE IF EXISTS `' . self::NAME . '`');
        $server->exec('CREATE DATABASE `' . self::NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $db = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], self::NAME, $config['charset']),
            $config['username'],
            $config['password'],
            $options
        );

        self::importSchema($db);
        (new MigrationRunner($db))->runPending();

        return self::$connection = $db;
    }

    public static function reset(PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::BUSINESS_TABLES as $table) {
            $db->exec('DELETE FROM `' . $table . '`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        CertificatesModelMigration::insertDefaultTemplates($db);
    }

    private static function importSchema(PDO $db): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Nie można odczytać database/schema.sql');
        }

        foreach (explode(';', $sql) as $statement) {
            $withoutComments = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            // Połączenie wskazuje już bazę testową — pomijamy przełączanie na bazę aplikacji.
            if ($withoutComments === '' || preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $withoutComments) === 1) {
                continue;
            }

            $db->exec($statement);
        }
    }
}
