<?php

declare(strict_types=1);

/**
 * Cron: uruchamiaj raz na dobę, np.:
 *   php c:\laragon\www\assistent_subscription\cron\send_reminders.php
 * Windows Task Scheduler / Linux crontab: 0 8 * * *
 */

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

use App\Database;
use App\Mailer;
use PHPMailer\PHPMailer\Exception as PhpMailerException;

const REMINDER_DAYS = 3;

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/reminders.log';
$startedAt = date('Y-m-d H:i:s');
file_put_contents($logFile, "[{$startedAt}] Cron start\n", FILE_APPEND | LOCK_EX);

$db = Database::getInstance()->getConnection();
$mailer = new Mailer();

$sql = <<<'SQL'
    SELECT
        ms.id,
        ms.nazwa_uslugi,
        ms.koszt_pln,
        ms.data_nastepnej_platnosci,
        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.email AS user_email
    FROM manager_subskrypcji ms
    INNER JOIN users u ON ms.user_id = u.id
    WHERE ms.data_nastepnej_platnosci = DATE_ADD(CURDATE(), INTERVAL :days DAY)
      AND u.email IS NOT NULL
      AND u.email != ''
SQL;

$stmt = $db->prepare($sql);
$stmt->execute(['days' => REMINDER_DAYS]);
$rows = $stmt->fetchAll();

$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    $firstName = (string) $row['first_name'];
    $serviceName = (string) $row['nazwa_uslugi'];
    $cost = number_format((float) $row['koszt_pln'], 2, ',', ' ');
    $paymentDate = (string) $row['data_nastepnej_platnosci'];
    $toEmail = (string) $row['user_email'];

    $subject = "Przypomnienie: płatność za {$serviceName} za 3 dni";

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1e293b;">
    <p>Cześć <strong>{$firstName}</strong>,</p>
    <p>za <strong>3 dni</strong> minie termin płatności za usługę <strong>{$serviceName}</strong>.</p>
    <p>Kwota do zapłaty: <strong>{$cost} PLN</strong>.</p>
    <p>Data płatności: <strong>{$paymentDate}</strong>.</p>
    <p style="color: #64748b; font-size: 14px;">— CertiSub Assistant</p>
</body>
</html>
HTML;

    $textBody = "Cześć {$firstName}, za 3 dni minie termin płatności za usługę {$serviceName}. "
        . "Kwota do zapłaty: {$cost} PLN. Data płatności: {$paymentDate}.";

    try {
        $mailer->send($toEmail, $subject, $htmlBody, $firstName, $textBody);
        ++$sent;
        file_put_contents(
            $logFile,
            "[{$startedAt}] OK → {$toEmail} (subskrypcja #{$row['id']}, {$serviceName})\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (PhpMailerException $e) {
        ++$failed;
        file_put_contents(
            $logFile,
            "[{$startedAt}] FAIL → {$toEmail} (subskrypcja #{$row['id']}): {$e->getMessage()}\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (\Throwable $e) {
        ++$failed;
        file_put_contents(
            $logFile,
            "[{$startedAt}] ERROR → {$toEmail} (subskrypcja #{$row['id']}): {$e->getMessage()}\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

$summary = sprintf(
    "[%s] Cron done — found: %d, sent: %d, failed: %d\n",
    $startedAt,
    count($rows),
    $sent,
    $failed
);
file_put_contents($logFile, $summary, FILE_APPEND | LOCK_EX);

if (PHP_SAPI === 'cli') {
    echo $summary;
}
