<?php

declare(strict_types=1);

namespace App;

/**
 * Prosty cache plikowy dla agregatów pulpitu i statystyk zadań (§2.5).
 *
 * Dwie zasady bezpieczeństwa i poprawności:
 *  1. Klucz zawiera rolę i właściciela rekordów — inaczej OPERATOR zobaczyłby liczby całej
 *     organizacji (to cofnęłoby naprawę zakresu danych z Etapu 0 i decyzję D8).
 *  2. Każdy zapis danych unieważnia cache: EventLogger podbija wersję, a wpisy z inną wersją
 *     są pomijane. Dzięki temu pulpit nigdy nie pokazuje liczb sprzed zmiany.
 *
 * Gdy katalog cache jest niedostępny (np. instalacja tylko do odczytu), wszystko działa dalej —
 * wartości liczą się za każdym razem.
 */
final class Cache
{
    public const DEFAULT_TTL = 300;

    private static ?string $directory = null;
    private static ?string $version = null;
    private static bool $enabled = true;

    public static function directory(): string
    {
        return self::$directory ??= dirname(__DIR__) . '/storage/cache';
    }

    /**
     * Katalog cache — testy i CLI mogą wskazać własny.
     */
    public static function useDirectory(?string $path): void
    {
        self::$directory = $path;
        self::$version = null;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Wartość z cache albo policzona i zapisana. Wartość musi dać się zapisać w JSON.
     *
     * @template T
     * @param callable(): T $factory
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $factory): mixed
    {
        if (!self::$enabled) {
            return $factory();
        }

        $file = self::path($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw !== false) {
            $entry = json_decode($raw, true);
            if (
                is_array($entry)
                && ($entry['version'] ?? null) === self::version()
                && (int) ($entry['expires'] ?? 0) > time()
                && array_key_exists('value', $entry)
            ) {
                return $entry['value'];
            }
        }

        $value = $factory();
        self::write($file, [
            'version' => self::version(),
            'expires' => time() + max(1, $ttl),
            'value'   => $value,
        ]);

        return $value;
    }

    /**
     * Unieważnia wszystkie wpisy — wołane przy każdym zapisie danych biznesowych.
     */
    public static function invalidate(): void
    {
        self::$version = null;
        self::write(self::versionFile(), ['version' => self::newVersion()], false);
    }

    /**
     * Bieżąca wersja danych; brak pliku oznacza pierwszy start (wersja powstaje wtedy od razu).
     */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $raw = is_file(self::versionFile()) ? @file_get_contents(self::versionFile()) : false;
        $entry = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($entry) && is_string($entry['version'] ?? null)) {
            return self::$version = $entry['version'];
        }

        $version = self::newVersion();
        self::write(self::versionFile(), ['version' => $version], false);

        return self::$version = $version;
    }

    /**
     * Usuwa pliki cache (np. przy czyszczeniu danych demonstracyjnych albo w testach).
     */
    public static function clear(): void
    {
        self::$version = null;
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function path(string $key): string
    {
        return self::directory() . '/' . substr(sha1($key), 0, 32) . '.json';
    }

    private static function versionFile(): string
    {
        return self::directory() . '/version.json';
    }

    private static function newVersion(): string
    {
        return dechex(random_int(0, PHP_INT_MAX)) . '-' . microtime(true);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function write(string $file, array $entry, bool $memoize = true): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        @file_put_contents($file, $json, LOCK_EX);
        if ($memoize && isset($entry['version']) && is_string($entry['version'])) {
            self::$version = $entry['version'];
        }
    }
}
