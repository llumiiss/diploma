<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AuthMailer;

/**
 * Atrapa wysyłki wiadomości uwierzytelniających: zapisuje linki, zamiast je wysyłać.
 * Testy odczytują z niej token z adresu, tak jak zrobiłaby to osoba klikająca w wiadomości.
 */
final class RecordingAuthMailer implements AuthMailer
{
    /** @var list<array{type: string, email: string, name: string, link: string}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public function sendEmailVerification(string $toEmail, string $toName, string $link): bool
    {
        return $this->record('verify', $toEmail, $toName, $link);
    }

    public function sendPasswordSetLink(string $toEmail, string $toName, string $link): bool
    {
        return $this->record('password', $toEmail, $toName, $link);
    }

    /**
     * Token z ostatniej wiadomości danego rodzaju — to, co odbiorca ma w linku.
     */
    public function lastToken(?string $type = null): string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($type !== null && $message['type'] !== $type) {
                continue;
            }

            parse_str((string) parse_url($message['link'], PHP_URL_QUERY), $query);

            return is_string($query['token'] ?? null) ? $query['token'] : '';
        }

        return '';
    }

    private function record(string $type, string $email, string $name, string $link): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->sent[] = ['type' => $type, 'email' => $email, 'name' => $name, 'link' => $link];

        return true;
    }
}
