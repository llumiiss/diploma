<?php

declare(strict_types=1);

/**
 * Połączenie z bazą danych.
 *
 * Wartości poniżej są dla pracy lokalnej (Laragon). Na serwerze **nie edytuj tego pliku** —
 * skopiuj `config/database.local.php.example` na `config/database.local.php` i wpisz dane tam.
 * Plik `*.local.php` jest poza repozytorium i żadne wdrożenie go nie nadpisze.
 *
 * Dlaczego tak: 2026-10-26 wdrożenie przez `deploy-pull.php` nadpisało produkcyjny
 * `config/database.php` wersją z repozytorium (localhost, root, puste hasło) i aplikacja straciła
 * połączenie z bazą — strona główna działała, a logowanie i API zwracały HTTP 500. Dane, które
 * różnią się między serwerami, mają mieszkać w plikach `*.local.php`.
 *
 * CERTISUB_DB_NAME pozwala uruchomić aplikację na innej bazie (np. kopii do przeglądu interfejsu)
 * bez zmiany plików konfiguracyjnych.
 */

$config = [
    'host'     => 'localhost',
    'dbname'   => getenv('CERTISUB_DB_NAME') ?: 'assistent_subscriptions',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];

$localFile = __DIR__ . '/database.local.php';

if (is_file($localFile)) {
    /** @var array<string, string> $local */
    $local = require $localFile;
    $config = array_replace($config, is_array($local) ? $local : []);

    // Zmienna środowiskowa dalej ma pierwszeństwo — tego używają testy i podgląd na kopii bazy.
    $override = getenv('CERTISUB_DB_NAME');
    if ($override !== false && $override !== '') {
        $config['dbname'] = $override;
    }
}

return $config;
