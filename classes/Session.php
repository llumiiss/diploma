<?php

declare(strict_types=1);

namespace App;

final class Session
{
    private static bool $configured = false;

    public static function ensureStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!self::$configured) {
            $secure = self::isHttps();
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => self::cookiePath(),
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            self::$configured = true;
        }

        session_start();
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Cookie path scoped to the app folder (e.g. /assistent_subscription/) or / for vhost docroot.
     */
    public static function cookiePath(): string
    {
        $folder = basename(dirname(__DIR__));
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($folder !== '' && str_contains($script, '/' . $folder . '/')) {
            return '/' . $folder . '/';
        }

        return '/';
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
    }
}
