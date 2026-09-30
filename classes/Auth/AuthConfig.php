<?php

declare(strict_types=1);

namespace App\Auth;

use App\Rbac;

/**
 * Ustawienia uwierzytelniania z pliku config/auth.php.
 *
 * Osobna klasa, bo o rejestrację pyta też strona startowa, która celowo nie łączy się
 * z bazą danych — pytanie „czy rejestracja jest włączona?” nie może wymagać MySQL-a.
 */
final class AuthConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    public static function selfRegistrationEnabled(): bool
    {
        return (bool) (self::all()['self_registration'] ?? false);
    }

    /**
     * Rola nadawana kontom z rejestracji publicznej — zawsze najniższa z dostępnych,
     * chyba że plik konfiguracyjny mówi inaczej. Uprawnienia podnosi administrator.
     */
    public static function defaultRole(): string
    {
        $role = (string) (self::all()['default_role'] ?? Rbac::OPERATOR);

        return Rbac::isRole($role) ? Rbac::normalize($role) : Rbac::OPERATOR;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file = dirname(__DIR__, 2) . '/config/auth.php';
        /** @var array<string, mixed> $loaded */
        $loaded = is_file($file) ? require $file : [];

        return self::$config = $loaded;
    }

    /**
     * Podmiana ustawień w testach.
     *
     * @param array<string, mixed>|null $config
     */
    public static function useConfig(?array $config): void
    {
        self::$config = $config;
    }
}
