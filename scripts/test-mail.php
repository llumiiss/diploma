<?php

declare(strict_types=1);

/**
 * Send a test email to verify SMTP / Mailtrap configuration.
 *
 *   php scripts/test-mail.php twoj@email.com
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\MailConfig;
use App\Mailer;

$to = $argv[1] ?? '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/test-mail.php recipient@example.com\n");
    exit(1);
}

$driver = MailConfig::driver();
$config = MailConfig::all();
$smtp = $config['smtp'] ?? [];

echo "Driver: {$driver}\n";
echo 'SMTP host: ' . ($smtp['host'] ?? 'n/a') . "\n";

if ($driver === 'sandbox') {
    echo "\nNOTE: Mailtrap Sandbox does NOT deliver to real inboxes.\n";
    echo "Check https://mailtrap.io → Email Testing → Inboxes\n\n";
}

try {
    $mailer = new Mailer();
    $mailer->send(
        $to,
        'CertiSub mail test',
        '<p>If you see this in Mailtrap or your inbox, mail configuration works.</p>',
        '',
        'If you see this in Mailtrap or your inbox, mail configuration works.'
    );
    echo "OK — message accepted by SMTP server for: {$to}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
