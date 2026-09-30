<?php

declare(strict_types=1);

namespace App;

final class MailConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $config = require dirname(__DIR__) . '/config/mail.php';
        $localFile = dirname(__DIR__) . '/config/mail.local.php';

        if (is_file($localFile)) {
            /** @var array<string, mixed> $local */
            $local = require $localFile;
            $config = array_replace_recursive($config, $local);
        }

        self::$config = $config;

        return self::$config;
    }

    public static function driver(): string
    {
        $driver = strtolower((string) (self::all()['driver'] ?? 'sandbox'));

        return in_array($driver, ['sandbox', 'smtp', 'oauth2', 'log'], true) ? $driver : 'sandbox';
    }

    public static function isSandbox(): bool
    {
        return self::driver() === 'sandbox';
    }

    public static function isProductionSmtp(): bool
    {
        return self::driver() === 'smtp';
    }

    /**
     * Wysyłka z autoryzacją OAuth2 (XOAUTH2) zamiast hasła do skrzynki.
     */
    public static function isOauth2(): bool
    {
        return self::driver() === 'oauth2';
    }

    /**
     * Blok „oauth2” konfiguracji: provider, client_id, client_secret, refresh_token, user_email.
     *
     * @return array<string, mixed>
     */
    public static function oauth2(): array
    {
        $oauth2 = self::all()['oauth2'] ?? [];

        return is_array($oauth2) ? $oauth2 : [];
    }

    /**
     * Wysyłka „na prawdziwy adres” — SMTP z hasłem albo SMTP z OAuth2.
     */
    public static function isRealDelivery(): bool
    {
        return self::isProductionSmtp() || self::isOauth2();
    }

    public static function shouldLogOtpCodes(): bool
    {
        if (self::isRealDelivery()) {
            return false;
        }

        return (bool) (self::all()['dev_log_codes'] ?? false);
    }
}
