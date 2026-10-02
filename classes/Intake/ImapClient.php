<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Minimalny klient IMAP4rev1 w czystym PHP — tylko to, czego potrzebuje odbiór wniosków: logowanie
 * (hasło albo XOAUTH2), wybór skrzynki, lista nieprzeczytanych, pobranie treści bez zmiany flag, oznaczenie
 * jako przeczytane i przeniesienie do innej skrzynki. Bez rozszerzenia imap, które nie jest już częścią PHP.
 *
 * Protokół: polecenia mają znacznik (A001…), odpowiedzi nieoznaczone zaczynają się od „*”, a pole
 * w postaci {N} oznacza literał — N bajtów, które następują po końcu linii. Hasło nigdy nie trafia do
 * komunikatów błędów: wyjątki zawierają tylko odpowiedź serwera.
 */
final class ImapClient
{
    private int $sequence = 0;

    public function __construct(private readonly ImapTransport $transport)
    {
    }

    /**
     * Odczytuje powitanie serwera („* OK …”).
     */
    public function greeting(): void
    {
        $line = $this->transport->readLine();
        if ($line === null || stripos($line, '* OK') !== 0 && stripos($line, '* PREAUTH') !== 0) {
            throw new ImapException('Serwer IMAP nie przywitał się poprawnie.');
        }
    }

    public function loginPassword(string $user, string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password), 'Logowanie IMAP nie powiodło się');
    }

    /**
     * Logowanie tokenem OAuth2 (XOAUTH2) — hasło do skrzynki nie jest potrzebne.
     */
    public function authenticateXOAuth2(string $user, string $accessToken): void
    {
        $initial = base64_encode('user=' . $user . "\001auth=Bearer " . $accessToken . "\001\001");
        $this->command('AUTHENTICATE XOAUTH2 ' . $initial, 'Logowanie IMAP (XOAUTH2) nie powiodło się');
    }

    public function select(string $mailbox): void
    {
        $this->command('SELECT ' . self::quote($mailbox), 'Nie można otworzyć skrzynki');
    }

    /**
     * Numery UID nieprzeczytanych wiadomości (najstarsze pierwsze), najwyżej $limit.
     *
     * @return list<int>
     */
    public function searchUnseen(int $limit): array
    {
        $response = $this->command('UID SEARCH UNSEEN', 'Wyszukiwanie wiadomości nie powiodło się');
        $uids = [];
        foreach ($response['untagged'] as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $uid) {
                    $uids[] = (int) $uid;
                }
            }
        }
        sort($uids);

        return array_slice($uids, 0, max(0, $limit));
    }

    public function messageSize(int $uid): ?int
    {
        $response = $this->command('UID FETCH ' . $uid . ' (RFC822.SIZE)', 'Nie można odczytać rozmiaru wiadomości');
        foreach ($response['untagged'] as $line) {
            if (preg_match('/RFC822\.SIZE\s+(\d+)/i', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Pełna treść wiadomości (BODY.PEEK nie ustawia flagi „przeczytana”).
     */
    public function fetchRaw(int $uid): string
    {
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])', 'Nie można pobrać wiadomości');
        if ($response['literals'] === []) {
            throw new ImapException('Serwer nie zwrócił treści wiadomości.');
        }

        return $response['literals'][0];
    }

    public function markSeen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)', 'Nie można oznaczyć wiadomości jako przeczytanej');
    }

    /**
     * Kopiuje wiadomość do innej skrzynki i usuwa ją z bieżącej (COPY + \Deleted + EXPUNGE).
     */
    public function moveTo(int $uid, string $mailbox): void
    {
        $this->command('UID COPY ' . $uid . ' ' . self::quote($mailbox), 'Nie można przenieść wiadomości');
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)', 'Nie można oznaczyć wiadomości do usunięcia');
        $this->command('EXPUNGE', 'Nie można usunąć wiadomości ze skrzynki');
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT', 'Wylogowanie nie powiodło się');
        } catch (ImapException) {
            // Zamykamy połączenie niezależnie od odpowiedzi serwera.
        }
        $this->transport->close();
    }

    /**
     * Wysyła polecenie i czyta odpowiedź do linii z naszym znacznikiem.
     *
     * @return array{text: string, untagged: list<string>, literals: list<string>}
     */
    private function command(string $command, string $failure): array
    {
        $tag = sprintf('A%03d', ++$this->sequence);
        $this->transport->write($tag . ' ' . $command . "\r\n");

        $untagged = [];
        $literals = [];
        while (true) {
            $line = $this->transport->readLine();
            if ($line === null) {
                throw new ImapException($failure . ': serwer zamknął połączenie.');
            }

            // Literał: {N} na końcu linii — N bajtów treści, po nich zwykle jeszcze koniec odpowiedzi w nowej linii.
            while (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
                $literals[] = $this->transport->readBytes((int) $m[1]);
                $rest = $this->transport->readLine();
                $line .= ' ' . ($rest ?? '');
            }

            if (str_starts_with($line, '+')) {
                // Serwer prosi o ciąg dalszy (XOAUTH2 przy błędzie wysyła opis) — pusta linia kończy wymianę.
                $this->transport->write("\r\n");
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                if (stripos($rest, 'OK') === 0) {
                    return ['text' => trim(substr($rest, 2)), 'untagged' => $untagged, 'literals' => $literals];
                }

                throw new ImapException($failure . ': ' . trim($rest));
            }

            $untagged[] = $line;
        }
    }

    private static function quote(string $value): string
    {
        // Znaki sterujące nie mają prawa wejść w polecenie — wstrzyknęłyby kolejną komendę.
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return '"' . addcslashes($clean, '\\"') . '"';
    }
}
