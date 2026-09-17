<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Ustawienia procesu odnowień: wartości domyślne z kodu nadpisane zmianami z tabeli settings.
 *
 * Odczyt jest leniwy i zapamiętywany na czas żądania. Gdy bazy nie ma (np. testy jednostkowe
 * albo instalacja przed migracją), obowiązują wartości domyślne.
 */
final class Settings
{
    /**
     * Klucz → [wartość domyślna, minimum, maksimum].
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    public const DEFINITIONS = [
        // Domyślny margines odnowienia (gdy certyfikat nie ma własnego) i próg „do odnowienia” na pulpicie.
        'renewal.warning_days'    => [30, 1, 365],
        // Próg „krytyczne”.
        'renewal.critical_days'   => [7, 0, 90],
        // Odstęp między przypomnieniami o niezałatwionym zaproszeniu.
        'reminders.interval_days' => [7, 1, 90],
        // Ile przypomnień najwyżej wysłać po jednym zaproszeniu.
        'reminders.max_count'     => [3, 0, 20],
    ];

    /** @var array<string, int>|null */
    private static ?array $values = null;

    private static bool $defaultsOnly = false;

    public static function int(string $key): int
    {
        $values = self::values();

        return $values[$key] ?? (self::DEFINITIONS[$key][0] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public static function values(): array
    {
        if (self::$values === null) {
            self::$values = self::defaults();
            if (!self::$defaultsOnly) {
                try {
                    self::$values = self::read(Database::getInstance()->getConnection());
                } catch (Throwable) {
                    self::$values = self::defaults();
                }
            }
        }

        return self::$values;
    }

    /**
     * Wartości z podanej bazy (z domyślnymi dla brakujących kluczy).
     *
     * @return array<string, int>
     */
    public static function read(PDO $db): array
    {
        $values = self::defaults();
        $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [] as $key => $value) {
            if (isset(self::DEFINITIONS[$key]) && is_numeric($value)) {
                [, $min, $max] = self::DEFINITIONS[$key];
                $values[$key] = max($min, min($max, (int) $value));
            }
        }

        return $values;
    }

    /**
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        return array_map(static fn (array $definition): int => $definition[0], self::DEFINITIONS);
    }

    /**
     * Po zapisie ustawień — następny odczyt pobierze nowe wartości.
     */
    public static function forget(): void
    {
        self::$values = null;
    }

    /**
     * Testy jednostkowe nie powinny zależeć od zawartości bazy aplikacji.
     */
    public static function useDefaultsOnly(bool $enabled = true): void
    {
        self::$defaultsOnly = $enabled;
        self::$values = null;
    }
}
