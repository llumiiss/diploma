<?php

declare(strict_types=1);

return [
    'host'     => 'localhost',
    // CERTISUB_DB_NAME pozwala uruchomić aplikację na innej bazie (np. kopii do przeglądu interfejsu)
    // bez zmiany pliku; bez zmiennej używana jest baza produkcyjna.
    'dbname'   => getenv('CERTISUB_DB_NAME') ?: 'assistent_subscriptions',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];
