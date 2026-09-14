<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Session::ensureStarted();

        if (
            !isset($_SESSION[self::SESSION_KEY])
            || !is_string($_SESSION[self::SESSION_KEY])
            || $_SESSION[self::SESSION_KEY] === ''
        ) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): bool
    {
        Session::ensureStarted();

        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $_SESSION[self::SESSION_KEY] ?? '';

        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function rotate(): void
    {
        Session::ensureStarted();
        unset($_SESSION[self::SESSION_KEY]);
        self::token();
    }
}
