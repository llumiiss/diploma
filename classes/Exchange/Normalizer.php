<?php

declare(strict_types=1);

namespace App\Exchange;

use App\Translator;

/**
 * Ujednolicanie wartości z plików przed walidacją w usługach: daty zapisane przez arkusz
 * po polsku (17.09.2026), wartości logiczne („tak”), słowniki podane etykietą zamiast kodu
 * oraz ochrona przed formułami w CSV (CSV injection).
 */
final class Normalizer
{
    /** Znaki, od których arkusz kalkulacyjny zaczyna formułę. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'í' => 'i', 'ú' => 'u',
    ];

    /** @var array<string, Translator> */
    private static array $translators = [];

    /**
     * Tekst zaczynający się od znaku formuły dostaje apostrof, żeby arkusz nie wykonał go jako formuły.
     */
    public static function protect(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true) ? "'" . $value : $value;
    }

    /**
     * Odwrotność protect() przy imporcie pliku wyeksportowanego z aplikacji.
     */
    public static function unprotect(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], self::FORMULA_PREFIXES, true)
            ? substr($value, 1)
            : $value;
    }

    /**
     * Klucz do porównywania nagłówków i etykiet: małe litery bez polskich znaków i separatorów.
     */
    public static function key(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), self::TRANSLITERATION);

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /**
     * Data w formacie RRRR-MM-DD z zapisów spotykanych w arkuszach; inne wartości bez zmian
     * (walidator usługi zgłosi błąd przy polu).
     */
    public static function date(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $value;
    }

    public static function bool(string $value): string
    {
        $key = self::key($value);

        return match (true) {
            in_array($key, ['1', 'tak', 't', 'yes', 'y', 'true', 'x', 'on'], true)  => '1',
            in_array($key, ['0', 'nie', 'n', 'no', 'false', 'off'], true)          => '0',
            default                                                                  => trim($value),
        };
    }

    /**
     * Wartość słownika: kod (dowolna wielkość liter) albo etykieta po polsku lub angielsku.
     *
     * @param array<string, string> $labelKeys kod → klucz tłumaczenia etykiety
     * @param array<string, string> $synonyms  dodatkowe nazwy (dowolny zapis) → kod
     */
    public static function choice(string $value, array $labelKeys, array $synonyms = []): string
    {
        $key = self::key($value);
        if ($key === '') {
            return '';
        }

        foreach ($synonyms as $synonym => $code) {
            if ($key === self::key($synonym)) {
                return $code;
            }
        }

        foreach ($labelKeys as $code => $labelKey) {
            if ($key === self::key($code)) {
                return $code;
            }
            foreach (['pl', 'en'] as $locale) {
                if ($key === self::key(self::translator($locale)->translate($labelKey))) {
                    return $code;
                }
            }
        }

        return trim($value);
    }

    public static function translator(string $locale): Translator
    {
        return self::$translators[$locale] ??= Translator::forLocale($locale);
    }

    /**
     * Tekst jako poprawny UTF-8 bez znaków niedozwolonych w XML 1.0.
     */
    public static function xmlText(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }
}
