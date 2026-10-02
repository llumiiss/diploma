<?php

declare(strict_types=1);

/**
 * Odbiór wniosków o certyfikat przesłanych e-mailem (Etap 10) — ustawienia domyślne, bezpieczne do commitu.
 * Hasła i tokeny wpisz w config/intake.local.php (plik jest poza repozytorium).
 *
 * Wniosek może trafić do aplikacji czterema drogami, wszystkie kończą się tym samym formularzem do sprawdzenia:
 *   1. ręcznie — przycisk „Wczytaj wiadomość (.eml)” w panelu (zapisz wiadomość z programu pocztowego),
 *   2. skrzynka IMAP — cron/mail-intake.php co kilka minut pobiera nieprzeczytane wiadomości,
 *   3. webhook — POST z treścią wiadomości (surowy MIME) na api/inbound-mail.php z nagłówkiem X-Intake-Token,
 *   4. potok serwera pocztowego — scripts/mail-pipe.php czyta wiadomość ze standardowego wejścia
 *      (np. alias Postfix: wnioski: "|php /sciezka/scripts/mail-pipe.php").
 */
return [
    // Rejestr firm po NIP-ie: Biała Lista podatników VAT (Ministerstwo Finansów), publiczne API bez klucza.
    'registry' => [
        'base_url' => 'https://wl-api.mf.gov.pl',
        'timeout'  => 8,
    ],

    // Webhook: pusty token wyłącza punkt wejścia api/inbound-mail.php. Wygeneruj długi losowy ciąg, np.:
    //   php -r "echo bin2hex(random_bytes(32));"
    'webhook' => [
        'token' => '',
    ],

    // Skrzynka IMAP odbierająca wnioski. auth: password (login i hasło/hasło aplikacji) albo oauth2
    // (ten sam refresh token co przy wysyłce — blok oauth2 w config/mail.local.php, uprawnienie IMAP dostawcy).
    'imap' => [
        'enabled'           => false,
        'host'              => 'imap.gmail.com',
        'port'              => 993,
        'encryption'        => 'ssl',      // ssl | none (none tylko dla localhost)
        'auth'              => 'password', // password | oauth2
        'username'          => '',
        'password'          => '',
        'mailbox'           => 'INBOX',
        'processed_mailbox' => '',         // pusta = tylko oznacz jako przeczytane; nazwa = przenieś tam wiadomość
        'max_per_run'       => 20,
        'timeout'           => 15,
    ],
];
