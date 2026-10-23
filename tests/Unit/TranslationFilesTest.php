<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Pilnuje kompletności tłumaczeń. Od 2026-10-23 wszystkie pięć języków ma ten sam zestaw
 * kluczy — wcześniej es/de/uk miały tylko 159 z 1278 kluczy i po przełączeniu języka
 * większość tekstów spadała na angielski.
 *
 * Nowy tekst dodajemy do WSZYSTKICH plików z lang/. Ten test przypomni o tym od razu.
 */
final class TranslationFilesTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private static array $lines = [];

    /**
     * @return array<string, string>
     */
    private static function lines(string $locale): array
    {
        if (!isset(self::$lines[$locale])) {
            /** @var array<string, string> $loaded */
            $loaded = require dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            self::$lines[$locale] = $loaded;
        }

        return self::$lines[$locale];
    }

    public function testEveryLocaleIsListedAsSupported(): void
    {
        foreach (Translator::SUPPORTED as $locale) {
            $this->assertFileExists(dirname(__DIR__, 2) . '/lang/' . $locale . '.php');
        }

        $this->assertContains(Translator::DEFAULT_LOCALE, Translator::SUPPORTED);
        $this->assertSame(array_keys(Translator::availableLocales()), Translator::SUPPORTED);
    }

    public function testAllLocalesHaveTheSameKeys(): void
    {
        $reference = array_keys(self::lines('en'));
        $this->assertGreaterThan(1000, count($reference), 'plik angielski jest podstawą pozostałych');

        foreach (Translator::SUPPORTED as $locale) {
            $keys = array_keys(self::lines($locale));

            $missing = array_diff($reference, $keys);
            $extra = array_diff($keys, $reference);

            $this->assertSame([], array_values($missing), "brakujące klucze w lang/{$locale}.php");
            $this->assertSame([], array_values($extra), "klucze bez odpowiednika w lang/en.php: lang/{$locale}.php");
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (Translator::SUPPORTED as $locale) {
            foreach (self::lines($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "pusty tekst: {$locale} / {$key}");
            }
        }
    }

    /**
     * Pola podstawiane (:name, :count …) muszą być te same w każdym języku — inaczej
     * w interfejsie pojawi się surowe „:count” albo zniknie liczba.
     */
    public function testPlaceholdersMatchTheEnglishOriginal(): void
    {
        foreach (self::lines('en') as $key => $english) {
            preg_match_all('/:[a-z_]+/', $english, $matches);
            $expected = array_unique($matches[0]);
            sort($expected);

            foreach (Translator::SUPPORTED as $locale) {
                if ($locale === 'en') {
                    continue;
                }

                preg_match_all('/:[a-z_]+/', self::lines($locale)[$key], $localeMatches);
                $actual = array_unique($localeMatches[0]);
                sort($actual);

                $this->assertSame($expected, $actual, "inne pola podstawiane: {$locale} / {$key}");
            }
        }
    }

    /**
     * Litery występujące tylko w polskim alfabecie zdradzają tekst skopiowany z lang/pl.php.
     * „ó” pomijamy, bo jest też po hiszpańsku.
     */
    public function testOnlyThePolishFileContainsPolishLetters(): void
    {
        foreach (['en', 'es', 'de', 'uk'] as $locale) {
            foreach (self::lines($locale) as $key => $value) {
                $this->assertSame(
                    0,
                    preg_match('/[ąćęłńśźżĄĆĘŁŃŚŹŻ]/u', $value),
                    "nieprzetłumaczony tekst (polskie znaki): {$locale} / {$key} = {$value}"
                );
            }
        }
    }

    /**
     * Klucze używane w kodzie przez __('…') i t('…') muszą istnieć. Wyłapuje literówki
     * w nazwach kluczy, które w interfejsie pokazują się jako surowy klucz.
     */
    public function testKeysUsedInTheCodeExist(): void
    {
        $root = dirname(__DIR__, 2);
        $english = self::lines('en');
        $undefined = [];

        foreach (self::sourceFiles($root) as $file) {
            $code = (string) file_get_contents($file);
            // Zamknięcie cudzysłowu musi kończyć argument — inaczej łapiemy przedrostki
            // kluczy budowanych w kodzie, np. t('task.status.' + status).
            if (preg_match_all('/(?:\b__|\\\\__|\bt|Translator::get)\(\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]\s*[,)]/i', $code, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $key) {
                if (!isset($english[$key])) {
                    $undefined[$key] = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame([], $undefined, 'klucze użyte w kodzie, których nie ma w lang/en.php');
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $root): array
    {
        $files = [];

        foreach (['classes', 'api', 'includes', 'assets/js', 'cron'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (['index.php', 'login.php', 'register.php', 'dashboard.php', 'verify-email.php', 'set-password.php'] as $file) {
            if (is_file($root . '/' . $file)) {
                $files[] = $root . '/' . $file;
            }
        }

        return $files;
    }
}
