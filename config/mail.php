<?php

declare(strict_types=1);

/**
 * Mail configuration (defaults — safe to commit).
 *
 * Copy config/mail.local.php.example → config/mail.local.php and put credentials there.
 *
 * Drivers:
 *   oauth2  — Real delivery over SMTP with OAuth2 (XOAUTH2): no mailbox password in config,
 *             only client_id + client_secret + refresh_token. Required flow for the
 *             account-verification e-mail. Get the refresh token with: php scripts/oauth2-token.php
 *   sandbox — Mailtrap Email Sandbox: mail appears ONLY in mailtrap.io inbox (NOT your Gmail).
 *   smtp    — Real delivery with a password (Gmail app password, Mailtrap Email Sending, SendGrid).
 *   log     — No sending: messages (with attachment names) are written to logs/mail.log.
 *             For demos and intranet installs without SMTP. Never in production — links land in the file.
 */
return [
    'driver' => 'sandbox',

    'from_email' => 'noreply@certisub.local',
    'from_name'  => 'CertiSub Assistant',

    // Base URL used in e-mail links (verification, set password). Empty = build from the request.
    'app_url' => '',

    // Fallback log for verification links when sending fails — keep false in normal use.
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

    // Used only when driver = oauth2. Credentials belong in config/mail.local.php.
    'oauth2' => [
        'provider'      => 'google',          // google | microsoft
        'host'          => 'smtp.gmail.com',  // microsoft: smtp.office365.com
        'port'          => 587,
        'smtp_secure'   => 'tls',
        'user_email'    => '',                // mailbox that sends the messages
        'client_id'     => '',
        'client_secret' => '',
        'refresh_token' => '',
        'tenant_id'     => '',                // microsoft only
        'token_endpoint' => '',               // optional override
    ],
];
