<?php

declare(strict_types=1);

/**
 * Odbiór wniosków o certyfikat ze skrzynki IMAP (Etap 10) — uruchamiaj co kilka minut:
 *   php c:\laragon\www\assistent_subscription\cron\mail-intake.php
 *
 * Pobiera nieprzeczytane wiadomości (najwyżej imap.max_per_run na przebieg), zamienia je na wnioski do sprawdzenia
 * przez operatora i oznacza jako przeczytane albo przenosi do skrzynki „przetworzone”. Wiadomości, które nie
 * wyglądają na wniosek, są pomijane (zostają jako przeczytane). Konfiguracja: config/intake.local.php.
 * Wynik trafia do logs/mail-intake.log. Błąd połączenia zostawia wiadomości nieprzeczytane do następnego przebiegu,
 * a administratorzy dostają o nim powiadomienie w aplikacji.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Intake\ImapException;
use App\Intake\IntakeConfig;
use App\Intake\MailboxConnector;
use App\Service\NotificationService;
use App\Service\RegistrationIntakeService;

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/mail-intake.log';

$log = static function (string $message) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
};

if (!IntakeConfig::imapEnabled()) {
    $log('Odbiór ze skrzynki IMAP jest wyłączony (imap.enabled w config/intake.local.php) — nic do zrobienia.');
    exit(0);
}

$imap = IntakeConfig::section('imap');

try {
    $db = Database::getInstance()->getConnection();
    $client = MailboxConnector::connect($imap);

    try {
        $summary = (new RegistrationIntakeService($db))->fetchMailbox(
            $client,
            (string) ($imap['mailbox'] ?? 'INBOX'),
            max(1, (int) ($imap['max_per_run'] ?? 20)),
            ($imap['processed_mailbox'] ?? '') !== '' ? (string) $imap['processed_mailbox'] : null
        );
    } finally {
        $client->logout();
    }

    $log(sprintf(
        'Skrzynka IMAP — pobrane: %d, nowe wnioski: %d, duplikaty: %d, pominięte: %d, nieczytelne: %d',
        $summary['fetched'],
        $summary['created'],
        $summary['duplicates'],
        $summary['skipped'],
        $summary['failed']
    ));
    foreach ($summary['errors'] as $error) {
        $log('  ' . $error);
    }

    exit(0);
} catch (ImapException $e) {
    $log('BŁĄD skrzynki IMAP: ' . $e->getMessage());

    // Administratorzy dowiedzą się o awarii w aplikacji — inaczej wnioski po prostu przestałyby napływać.
    try {
        $notifications = new NotificationService(Database::getInstance()->getConnection());
        $admins = array_filter(
            $notifications->usersWithPermission('settings.manage'),
            static fn (int $id): bool => $id > 0
        );
        $notifications->notify(
            array_values($admins),
            __('registration.notification.imap_failed_subject'),
            __('registration.notification.imap_failed_body', ['message' => $e->getMessage()]),
            null,
            null,
            'error'
        );
    } catch (Throwable $inner) {
        $log('Nie udało się powiadomić administratorów: ' . $inner->getMessage());
    }

    exit(1);
} catch (Throwable $e) {
    $log('BŁĄD: ' . $e::class . ': ' . $e->getMessage());
    exit(1);
}
