<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Konfiguracja odbioru wniosków e-mail: rejestr firm, webhook i skrzynka IMAP.
 * Wartości domyślne są w config/intake.php (bezpieczne do wersjonowania), a hasła i tokeny w
 * config/intake.local.php (poza repozytorium) — tak samo jak konfiguracja poczty wychodzącej.
 */
final class IntakeConfig
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

        $config = require dirname(__DIR__, 2) . '/config/intake.php';
        $localFile = dirname(__DIR__, 2) . '/config/intake.local.php';
        if (is_file($localFile)) {
            /** @var array<string, mixed> $local */
            $local = require $localFile;
            $config = array_replace_recursive($config, $local);
        }

        return self::$config = $config;
    }

    /**
     * Wymusza ponowne wczytanie plików (testy).
     */
    public static function reset(): void
    {
        self::$config = null;
    }

    /**
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>
     */
    public static function section(string $name, ?array $override = null): array
    {
        $section = $override[$name] ?? self::all()[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    public static function webhookToken(): string
    {
        return trim((string) (self::section('webhook')['token'] ?? ''));
    }

    public static function registry(): CompanyRegistry
    {
        $registry = self::section('registry');

        return new WhiteListRegistry(
            (string) ($registry['base_url'] ?? WhiteListRegistry::DEFAULT_BASE_URL),
            max(1, (int) ($registry['timeout'] ?? 8))
        );
    }

    public static function imapEnabled(): bool
    {
        $imap = self::section('imap');

        return (bool) ($imap['enabled'] ?? false) && trim((string) ($imap['host'] ?? '')) !== '' && trim((string) ($imap['username'] ?? '')) !== '';
    }
}
