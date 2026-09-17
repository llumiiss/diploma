<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Wysyłka zaproszeń i przypomnień. Wydzielona jako interfejs, żeby testy mogły sprawdzać
 * treść i załączniki bez połączenia z serwerem pocztowym.
 */
interface InvitationMailer
{
    /**
     * @param list<array{path: string, name: string}> $attachments
     *
     * @throws \Throwable gdy wiadomości nie udało się wysłać (komunikat trafia do rejestru zaproszeń)
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $attachments): void;
}
