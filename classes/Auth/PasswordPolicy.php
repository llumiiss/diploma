<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Wymagania wobec haseł, zgodne z zaleceniami NIST SP 800-63B: liczy się długość,
 * a nie wymyślne zestawy znaków. Stąd minimum 10 znaków i tylko jeden dodatkowy
 * warunek (litera + cyfra lub znak specjalny), który odrzuca hasła typu „aaaaaaaaaa”.
 *
 * Górna granica 4096 znaków chroni przed przeciążeniem funkcji haszującej długim wejściem.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 4096;

    /**
     * Hasła, które w praktyce są pierwszym strzałem atakującego. Krótsze warianty
     * („haslo123”) odpada już na warunku długości, więc lista wymienia tylko te,
     * które długość przechodzą.
     */
    private const FORBIDDEN = [
        'password12', 'password123', 'haslo12345', 'haslo123456', 'qwerty1234',
        'qwerty12345', '1234567890', 'certisub123', 'administrator', 'admin12345',
        'zaq12wsxcde', 'iloveyou12',
    ];

    /**
     * @return string|null Klucz tłumaczenia z opisem problemu albo null, gdy hasło jest w porządku.
     */
    public static function validate(string $password, string $email = ''): ?string
    {
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            return 'auth.error.password_too_short';
        }

        if ($length > self::MAX_LENGTH) {
            return 'auth.error.password_too_long';
        }

        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/[\p{N}\p{P}\p{S}]/u', $password) !== 1) {
            return 'auth.error.password_too_simple';
        }

        $lower = mb_strtolower($password);

        if (in_array($lower, self::FORBIDDEN, true)) {
            return 'auth.error.password_common';
        }

        $localPart = mb_strtolower(explode('@', $email)[0] ?? '');
        if ($localPart !== '' && mb_strlen($localPart) >= 4 && str_contains($lower, $localPart)) {
            return 'auth.error.password_contains_email';
        }

        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
