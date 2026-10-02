<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Transport IMAP na gnieździe strumieniowym PHP. Połączenie szyfrowane (ssl) weryfikuje certyfikat serwera;
 * „none” (bez szyfrowania) jest dozwolone wyłącznie dla serwera na tej samej maszynie.
 */
final class StreamImapTransport implements ImapTransport
{
    /** @var resource|null */
    private $stream;

    /**
     * @param resource $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function open(string $host, int $port, string $encryption, int $timeout): self
    {
        $encryption = strtolower($encryption);
        if ($encryption === 'none' && !in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new ImapException('Połączenie IMAP bez szyfrowania jest dozwolone tylko z localhost.');
        }
        if (!in_array($encryption, ['ssl', 'none'], true)) {
            throw new ImapException('Nieznany rodzaj szyfrowania IMAP: ' . $encryption . ' (dozwolone: ssl, none).');
        }

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @stream_socket_client(
            ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new ImapException('Nie można połączyć się z serwerem IMAP ' . $host . ':' . $port . ' (' . $errorMessage . ').');
        }
        stream_set_timeout($stream, $timeout);

        return new self($stream);
    }

    public function readLine(): ?string
    {
        if ($this->stream === null) {
            return null;
        }
        $line = fgets($this->stream);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    public function readBytes(int $length): string
    {
        $data = '';
        while ($this->stream !== null && strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new ImapException('Połączenie IMAP przerwane w trakcie pobierania wiadomości.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    public function write(string $data): void
    {
        if ($this->stream === null || fwrite($this->stream, $data) === false) {
            throw new ImapException('Nie można wysłać polecenia do serwera IMAP.');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
