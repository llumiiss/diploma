<?php

declare(strict_types=1);

/**
 * Odpowiednik cron/renewals.php do uruchamiania przez zewnętrzny harmonogram HTTP
 * (np. https://cron-job.org) na hostingu bez prawdziwego crontaba (np. InfinityFree).
 * Chronione tokenem z config/deploy.local.php (skopiuj z deploy.local.php.example).
 *
 * W panelu harmonogramu ustaw codzienne wywołanie GET:
 *   https://twoja-domena/deploy-cron.php?token=...
 *
 * Robi dokładnie to samo co cron/renewals.php: skaner odnowień (zakłada zadania ToDo)
 * i wysyłkę zaległych przypomnień o zaproszeniach. Wynik dopisywany do logs/renewals.log.
 */

require __DIR__ . '/bootstrap.php';

use App\Database;
use App\Service\InvitationService;
use App\Service\RenewalScanner;

header('Content-Type: text/plain; charset=utf-8');

$deployConfigFile = __DIR__ . '/config/deploy.local.php';
if (!is_file($deployConfigFile)) {
    http_response_code(403);
    exit("Brak config/deploy.local.php — skopiuj config/deploy.local.php.example i ustaw własny token.\n");
}

/** @var array<string, string> $deployConfig */
$deployConfig = require $deployConfigFile;
$expectedToken = (string) ($deployConfig['cron_token'] ?? '');
$token = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit("Forbidden.\n");
}

$logDir = __DIR__ . '/logs';
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
} catch (Throwable $e) {
    http_response_code(500);
    $log('BŁĄD: ' . $e->getMessage());
}
