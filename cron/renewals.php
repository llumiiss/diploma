<?php

declare(strict_types=1);

/**
 * Cron procesu odnowień (F11, F14) — uruchamiaj raz na dobę, np. o 7:00:
 *   php c:\laragon\www\assistent_subscription\cron\renewals.php
 *
 * 1. Skaner odnowień: zakłada zadania ToDo dla certyfikatów w marginesie odnowienia
 *    i podnosi priorytet zadań, gdy zbliża się data wygaśnięcia.
 * 2. Przypomnienia: wysyła przypomnienia o zaproszeniach, których termin minął
 *    (odstęp i limit z ustawień administratora).
 *
 * Wynik trafia do logs/renewals.log i historii zdarzeń. Ponowne uruchomienie tego samego dnia
 * nie tworzy duplikatów zadań ani przypomnień.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Service\InvitationService;
use App\Service\RenewalScanner;

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/renewals.log';

$log = static function (string $message) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
};

try {
    $db = Database::getInstance()->getConnection();

    $scan = (new RenewalScanner($db))->run();
    $log(sprintf(
        'Skaner odnowień — certyfikaty w marginesie: %d, nowe zadania: %d, zmienione priorytety: %d',
        $scan['scanned'],
        $scan['created'],
        $scan['updated']
    ));

    $reminders = (new InvitationService($db))->processDueReminders();
    $log(sprintf(
        'Przypomnienia — do wysłania: %d, wysłane: %d, nieudane: %d',
        $reminders['due'],
        $reminders['sent'],
        $reminders['failed']
    ));

    exit(0);
} catch (Throwable $e) {
    $log('BŁĄD: ' . $e->getMessage());
    exit(1);
}
