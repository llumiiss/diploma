<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Warstwa transportu klienta IMAP: odczyt linii i bajtów oraz zapis. Interfejs pozwala w testach
 * podstawić serwer ze scenariuszem zamiast prawdziwego gniazda.
 */
interface ImapTransport
{
    /**
     * Jedna linia odpowiedzi bez końca linii; null, gdy połączenie zostało zamknięte.
     */
    public function readLine(): ?string;

    /**
     * Dokładnie $length bajtów (literał IMAP).
     */
    public function readBytes(int $length): string;

    public function write(string $data): void;

    public function close(): void;
}
