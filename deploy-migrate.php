<?php

declare(strict_types=1);

/**
 * Jednorazowe narzędzie do uruchamiania migracji bazy na hostingu bez dostępu SSH/CLI
 * (np. InfinityFree) — odpowiednik `php scripts/migrate.php` dostępny przez HTTP.
 * Chronione tokenem z config/deploy.local.php (skopiuj z deploy.local.php.example).
 *
 *   https://twoja-domena/deploy-migrate.php?token=...            — uruchamia zaległe migracje
 *   https://twoja-domena/deploy-migrate.php?token=...&fresh=1    — importuje database/schema.sql
 *                                                                    (KASUJE WSZYSTKIE TABELE!) i migracje
 *
 * Używać tylko przy wdrożeniu. USUŃ TEN PLIK Z SERWERA (albo zmień token na losowy
 * i tak zostaw wyłącznie na czas wdrożenia), gdy baza jest już gotowa.
 */

require __DIR__ . '/bootstrap.php';

use App\MigrationRunner;

header('Content-Type: text/plain; charset=utf-8');

$deployConfigFile = __DIR__ . '/config/deploy.local.php';
if (!is_file($deployConfigFile)) {
    http_response_code(403);
    exit("Brak config/deploy.local.php — skopiuj config/deploy.local.php.example i ustaw własny token.\n");
}

/** @var array<string, string> $deployConfig */
$deployConfig = require $deployConfigFile;
$expectedToken = (string) ($deployConfig['migrate_token'] ?? '');
$token = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit("Forbidden.\n");
}

try {
    $config = require __DIR__ . '/config/database.php';

    if (($_GET['fresh'] ?? '') === '1') {
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            http_response_code(500);
            exit("Brak database/schema.sql\n");
        }

        $dsn = sprintf('mysql:host=%s;charset=%s', $config['host'], $config['charset']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            http_response_code(500);
            exit("Nie udało się odczytać schema.sql\n");
        }

        foreach (array_map('trim', explode(';', $sql)) as $statement) {
            $withoutComments = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            if ($withoutComments !== '') {
                $pdo->exec($statement);
            }
        }

        echo "Zaimportowano świeży schemat do {$config['dbname']}.\n";
    }

    $runner = new MigrationRunner();
    $pending = $runner->status();

    if ($pending === []) {
        echo "Baza jest aktualna ({$config['dbname']}).\n";
        exit;
    }

    echo 'Zaległe migracje: ' . implode(', ', $pending) . "\n";
    $applied = $runner->runPending();
    echo $applied === []
        ? "Nie zastosowano żadnej migracji.\n"
        : 'Zastosowano: ' . implode(', ', $applied) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Błąd: ' . $e->getMessage() . "\n";
}
