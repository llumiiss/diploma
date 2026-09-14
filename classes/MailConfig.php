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

        return in_array($driver, ['sandbox', 'smtp'], true) ? $driver : 'sandbox';
    }

    public static function isSandbox(): bool
    {
        return self::driver() === 'sandbox';
    }

    public static function isProductionSmtp(): bool
    {
        return self::driver() === 'smtp';
    }

    public static function shouldLogOtpCodes(): bool
    {
        if (self::isProductionSmtp()) {
            return false;
        }

        return (bool) (self::all()['dev_log_codes'] ?? false);
    }
}
