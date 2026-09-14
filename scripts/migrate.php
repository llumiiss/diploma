<?php

declare(strict_types=1);

/**
 * Database migrations for existing installations.
 *
 * Laragon (PowerShell, from project root):
 *   C:\laragon\bin\php\php-8.x.x\php.exe scripts\migrate.php
 *
 * Or if php is in PATH:
 *   php scripts\migrate.php
 *
 * Fresh install (drops demo data — use only on empty DB):
 *   php scripts\migrate.php --fresh
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\MigrationRunner;

$fresh = in_array('--fresh', $argv ?? [], true);
$config = require dirname(__DIR__) . '/config/database.php';

if ($fresh) {
    $schemaFile = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($schemaFile)) {
        fwrite(STDERR, "Missing database/schema.sql\n");
        exit(1);
    }

    $dsn = sprintf(
        'mysql:host=%s;charset=%s',
        $config['host'],
        $config['charset']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        fwrite(STDERR, "Could not read schema.sql\n");
        exit(1);
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    echo "Fresh schema imported into {$config['dbname']}.\n";
    exit(0);
}

try {
    $runner = new MigrationRunner();
    $pending = $runner->status();

    if ($pending === []) {
        echo "Database is up to date ({$config['dbname']}).\n";
        exit(0);
    }

    echo "Pending migrations: " . implode(', ', $pending) . "\n";
    $applied = $runner->runPending();

    if ($applied === []) {
        echo "No migrations were applied.\n";
        exit(0);
    }

    echo "Applied: " . implode(', ', $applied) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
