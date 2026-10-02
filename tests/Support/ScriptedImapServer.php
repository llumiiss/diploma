<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Intake\ImapTransport;

/**
 * Serwer IMAP ze scenariuszem dla testów klienta: rozumie polecenia używane przez aplikację i odpowiada
 * jak prawdziwy serwer (odpowiedzi nieoznaczone, literały {N}, linia z tagiem). Wszystkie polecenia
 * i zmiany flag są zapisywane do sprawdzenia w teście.
 */
final class ScriptedImapServer implements ImapTransport
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<int, array{raw: string, seen: bool, deleted: bool}> */
    public array $messages = [];

    /** @var array<string, list<string>> skrzynki, do których kopiowano wiadomości */
    public array $copied = [];

    public bool $closed = false;
    public bool $expunged = false;

    /** @var list<string> */
    private array $buffer = [];

    private string $pendingBytes = '';

    public function __construct(
        private readonly string $password = 'sekret',
        private readonly bool $greet = true,
        private readonly ?int $dropAfterCommands = null,
    ) {
        if ($greet) {
            $this->buffer[] = '* OK IMAP4rev1 ready';
        }
    }

    public function addMessage(int $uid, string $raw, bool $seen = false): void
    {
        $this->messages[$uid] = ['raw' => $raw, 'seen' => $seen, 'deleted' => false];
    }

    public function readLine(): ?string
    {
        if ($this->buffer === []) {
            return null;
        }

        return array_shift($this->buffer);
    }

    public function readBytes(int $length): string
    {
        $chunk = substr($this->pendingBytes, 0, $length);
        $this->pendingBytes = (string) substr($this->pendingBytes, $length);

        return $chunk;
    }

    public function write(string $data): void
    {
        foreach (preg_split('/\r\n/', trim($data, "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $this->commands[] = $line;
            $this->handle($line);
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function handle(string $line): void
    {
        if ($this->dropAfterCommands !== null && count($this->commands) > $this->dropAfterCommands) {
            $this->buffer = [];

            return;
        }

        [$tag, $rest] = array_pad(explode(' ', $line, 2), 2, '');

        if (str_starts_with($rest, 'LOGIN ')) {
            preg_match('/^LOGIN "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"$/', $rest, $m);
            $given = stripslashes($m[2] ?? '');
            $this->buffer[] = $given === $this->password ? "{$tag} OK LOGIN completed" : "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials";

            return;
        }

        if (str_starts_with($rest, 'AUTHENTICATE XOAUTH2 ')) {
            $decoded = (string) base64_decode(substr($rest, strlen('AUTHENTICATE XOAUTH2 ')), true);
            $ok = str_contains($decoded, "auth=Bearer good-token\001");
            if ($ok) {
                $this->buffer[] = "{$tag} OK Authenticated";
            } else {
                $this->buffer[] = '+ eyJzdGF0dXMiOiI0MDEifQ==';
                $this->buffer[] = "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials";
            }

            return;
        }

        if (str_starts_with($rest, 'SELECT ')) {
            $this->buffer[] = '* ' . count($this->messages) . ' EXISTS';
            $this->buffer[] = "{$tag} OK [READ-WRITE] SELECT completed";

            return;
        }

        if ($rest === 'UID SEARCH UNSEEN') {
            $unseen = [];
            foreach ($this->messages as $uid => $message) {
                if (!$message['seen'] && !$message['deleted']) {
                    $unseen[] = $uid;
                }
            }
            $this->buffer[] = '* SEARCH' . ($unseen === [] ? '' : ' ' . implode(' ', $unseen));
            $this->buffer[] = "{$tag} OK SEARCH completed";

            return;
        }

        if (preg_match('/^UID FETCH (\d+) \(RFC822\.SIZE\)$/', $rest, $m) === 1) {
            $uid = (int) $m[1];
            $size = isset($this->messages[$uid]) ? strlen($this->messages[$uid]['raw']) : 0;
            $this->buffer[] = "* 1 FETCH (UID {$uid} RFC822.SIZE {$size})";
            $this->buffer[] = "{$tag} OK FETCH completed";

            return;
        }

        if (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]\)$/', $rest, $m) === 1) {
            $uid = (int) $m[1];
            $raw = $this->messages[$uid]['raw'] ?? '';
            $this->pendingBytes = $raw;
            $this->buffer[] = '* 1 FETCH (UID ' . $uid . ' BODY[] {' . strlen($raw) . '}';
            $this->buffer[] = ')';
            $this->buffer[] = "{$tag} OK FETCH completed";

            return;
        }

        if (preg_match('/^UID STORE (\d+) \+FLAGS\.SILENT \(\\\\(Seen|Deleted)\)$/', $rest, $m) === 1) {
            $uid = (int) $m[1];
            if (isset($this->messages[$uid])) {
                $this->messages[$uid][strtolower($m[2]) === 'seen' ? 'seen' : 'deleted'] = true;
            }
            $this->buffer[] = "{$tag} OK STORE completed";

            return;
        }

        if (preg_match('/^UID COPY (\d+) "((?:[^"\\\\]|\\\\.)*)"$/', $rest, $m) === 1) {
            $this->copied[stripslashes($m[2])][] = $this->messages[(int) $m[1]]['raw'] ?? '';
            $this->buffer[] = "{$tag} OK COPY completed";

            return;
        }

        if ($rest === 'EXPUNGE') {
            $this->expunged = true;
            $this->messages = array_filter($this->messages, static fn (array $message): bool => !$message['deleted']);
            $this->buffer[] = "{$tag} OK EXPUNGE completed";

            return;
        }

        if ($rest === 'LOGOUT') {
            $this->buffer[] = '* BYE logging out';
            $this->buffer[] = "{$tag} OK LOGOUT completed";

            return;
        }

        $this->buffer[] = "{$tag} BAD Unknown command";
    }
}
