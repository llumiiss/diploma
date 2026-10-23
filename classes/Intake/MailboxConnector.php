<?php

declare(strict_types=1);

namespace App\Intake;

use App\Mail\OAuth2TokenProvider;
use RuntimeException;

/**
 * Otwiera zalogowane połączenie IMAP według sekcji „imap” konfiguracji odbioru wniosków.
 * Hasło trafia wyłącznie do polecenia LOGIN, a przy autoryzacji OAuth2 serwer dostaje tylko krótkotrwały token.
 */
final class MailboxConnector
{
    /**
     * @param array<string, mixed>      $imap      sekcja „imap” z config/intake.php
     * @param array<string, mixed>|null $oauth2    blok „oauth2” konfiguracji poczty (domyślnie z config/mail*.php)
     */
    public static function connect(array $imap, ?ImapTransport $transport = null, ?array $oauth2 = null): ImapClient
    {
        $user = trim((string) ($imap['username'] ?? ''));
        if ($user === '') {
            throw new ImapException(\__('imap.error.no_username'));
        }

        $transport ??= StreamImapTransport::open(
            (string) ($imap['host'] ?? ''),
            (int) ($imap['port'] ?? 993),
            (string) ($imap['encryption'] ?? 'ssl'),
            max(1, (int) ($imap['timeout'] ?? 15))
        );

        $client = new ImapClient($transport);
        try {
            $client->greeting();

            if (strtolower((string) ($imap['auth'] ?? 'password')) === 'oauth2') {
                try {
                    $token = (new OAuth2TokenProvider($oauth2))->accessToken();
                } catch (RuntimeException $e) {
                    throw new ImapException(\__('imap.error.oauth2_failed', ['message' => $e->getMessage()]));
                }
                $client->authenticateXOAuth2($user, $token);
            } else {
                $password = (string) ($imap['password'] ?? '');
                if ($password === '') {
                    throw new ImapException(\__('imap.error.no_password'));
                }
                $client->loginPassword($user, $password);
            }
        } catch (ImapException $e) {
            $transport->close();
            throw $e;
        }

        return $client;
    }
}
