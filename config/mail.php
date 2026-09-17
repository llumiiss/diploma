<?php

declare(strict_types=1);

/**
 * Mail configuration (defaults — safe to commit).
 *
 * Copy config/mail.local.php.example → config/mail.local.php and put credentials there.
 *
 * Drivers:
 *   sandbox — Mailtrap Email Sandbox: mail appears ONLY in mailtrap.io inbox (NOT your Gmail).
 *   smtp    — Real delivery (Gmail app password, Mailtrap Email Sending, SendGrid, etc.).
 *   log     — No sending: messages (with attachment names) are written to logs/mail.log.
 *             For demos and intranet installs without SMTP. Never in production — login codes land in the file.
 */
return [
    'driver' => 'sandbox',

    'from_email' => 'noreply@certisub.local',
    'from_name'  => 'CertiSub Assistant',

    // Fallback OTP log when SMTP fails — keep false in normal use.
    'dev_log_codes' => false,

    'smtp' => [
        'enabled'     => true,
        'host'        => 'sandbox.smtp.mailtrap.io',
        'port'        => 2525,
        'username'    => 'your_mailtrap_username',
        'password'    => 'your_mailtrap_password',
        'smtp_secure' => 'tls',
        'auth'        => true,
    ],
];
 