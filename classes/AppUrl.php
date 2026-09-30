<?php

declare(strict_types=1);

namespace App;

/**
 * Bezwzględne adresy do linków w wiadomościach e-mail (potwierdzenie adresu, ustawienie hasła).
 *
 * Adres bierzemy w pierwszej kolejności z konfiguracji (mail.app_url), bo tylko ona jest
 * wiarygodna: nagłówek Host przychodzi od klienta, więc gdyby link budować wyłącznie z żądania,
 * ktoś mógłby podstawić własną domenę i przejąć token z cudzej wiadomości (atak na nagłówek Host).
 * Z żądania korzystamy tylko wtedy, gdy konfiguracja milczy — wygoda przy pracy na localhost.
 */
final class AppUrl
{
    public static function base(): string
    {
        $configured = MailConfig::all()['app_url'] ?? '';
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $scheme = self::isHttps() ? 'https' : 'http';
        $host = self::host();
        $directory = self::directory();

        return $scheme . '://' . $host . $directory;
    }

    /**
     * @param array<string, string> $query
     */
    public static function to(string $path, array $query = []): string
    {
        $url = self::base() . '/' . ltrim($path, '/');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    private static function host(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        // Dopuszczamy tylko znaki nazwy hosta i portu — resztę odrzucamy razem z całą wartością.
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) === 1) {
            return $host;
        }

        return 'localhost';
    }

    /**
     * Katalog aplikacji (np. /assistent_subscription) — w CLI i przy braku danych: pusty.
     */
    private static function directory(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = rtrim(dirname($script), '/');

        return $directory === '' || $directory === '.' ? '' : $directory;
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
