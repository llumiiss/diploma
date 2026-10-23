<?php

declare(strict_types=1);

namespace App\Deploy;

use Generator;

/**
 * Czytanie archiwum .tar / .tar.gz w czystym PHP.
 *
 * Po co własny czytnik, skoro jest PharData: na współdzielonym hostingu rozszerzenie phar
 * bywa wyłączone (`suhosin`, `disable_classes`), a ZipArchive nie ma nawet lokalnie
 * (sprawdzone: `class_exists('ZipArchive') === false`). Format tar to stałe nagłówki po 512 bajtów,
 * więc czytnik mieści się w kilkudziesięciu wierszach i nie wymaga żadnego rozszerzenia poza zlib.
 *
 * Obsługiwane wpisy: zwykłe pliki i katalogi, nazwy z pola `prefix` (ustar), długie nazwy GNU ('L')
 * oraz nagłówki pax ('x' i 'g') — z 'x' bierzemy ścieżkę, 'g' pomijamy. Pozostałe typy
 * (dowiązania, urządzenia) są pomijane: w paczce z kodem nie mają czego robić.
 *
 * @see https://www.gnu.org/software/tar/manual/html_node/Standard.html
 */
final class TarArchive
{
    private const BLOCK = 512;

    /** Typy wpisów, które przenosimy dalej. */
    private const TYPE_FILE = ['0', "\0", ''];
    private const TYPE_DIRECTORY = '5';
    private const TYPE_LONG_NAME = 'L';
    private const TYPE_PAX = 'x';
    private const TYPE_PAX_GLOBAL = 'g';

    public function __construct(private readonly string $raw)
    {
    }

    /**
     * Rozpakowuje archiwum spakowane gzipem (.tar.gz / .tgz).
     *
     * @throws DeployException gdy danych nie da się rozpakować
     */
    public static function fromGzip(string $gzipped): self
    {
        if (!function_exists('gzdecode')) {
            throw new DeployException('Brak rozszerzenia zlib — nie mogę rozpakować archiwum .tar.gz.');
        }

        // Dwa pierwsze bajty gzipu to 0x1f 0x8b. Bez tego sprawdzenia błąd HTML-a z serwera
        // (np. strona logowania zamiast pliku) wyglądałby jak uszkodzone archiwum.
        if (strncmp($gzipped, "\x1f\x8b", 2) !== 0) {
            throw new DeployException('Pobrane dane nie są archiwum .tar.gz (brak nagłówka gzip).');
        }

        $raw = @gzdecode($gzipped);
        if ($raw === false || $raw === '') {
            throw new DeployException('Nie udało się rozpakować archiwum .tar.gz.');
        }

        return new self($raw);
    }

    /**
     * Wpisy archiwum po kolei. Treść pliku jest trzymana w pamięci tylko na czas jednego wpisu.
     *
     * @return Generator<int, array{path: string, type: string, size: int, content: string}>
     *
     * @throws DeployException gdy nagłówek jest uszkodzony
     */
    public function entries(): Generator
    {
        $offset = 0;
        $length = strlen($this->raw);
        $longName = null;
        $paxPath = null;

        while ($offset + self::BLOCK <= $length) {
            $header = substr($this->raw, $offset, self::BLOCK);
            $offset += self::BLOCK;

            // Dwa puste bloki kończą archiwum.
            if (trim($header, "\0") === '') {
                continue;
            }

            $name = self::text(substr($header, 0, 100));
            $prefix = self::text(substr($header, 345, 155));
            $type = substr($header, 156, 1);
            $size = self::octal(substr($header, 124, 12));

            if ($size < 0 || $offset + $size > $length) {
                throw new DeployException('Uszkodzony nagłówek archiwum (rozmiar wpisu poza plikiem).');
            }

            $content = substr($this->raw, $offset, $size);
            $offset += (int) ceil($size / self::BLOCK) * self::BLOCK;

            if ($type === self::TYPE_LONG_NAME) {
                $longName = self::text($content);
                continue;
            }

            if ($type === self::TYPE_PAX) {
                $paxPath = self::paxPath($content);
                continue;
            }

            if ($type === self::TYPE_PAX_GLOBAL) {
                continue;
            }

            $path = $paxPath ?? $longName ?? ($prefix !== '' ? $prefix . '/' . $name : $name);
            $longName = null;
            $paxPath = null;

            if ($type === self::TYPE_DIRECTORY) {
                yield ['path' => rtrim($path, '/'), 'type' => 'dir', 'size' => 0, 'content' => ''];
                continue;
            }

            if (!in_array($type, self::TYPE_FILE, true)) {
                continue;
            }

            yield ['path' => $path, 'type' => 'file', 'size' => $size, 'content' => $content];
        }
    }

    /**
     * Ścieżka z nagłówka pax: rekordy „<długość> <klucz>=<wartość>\n”.
     */
    private static function paxPath(string $content): ?string
    {
        if (preg_match('/\d+ path=([^\n]+)\n/', $content, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private static function text(string $field): string
    {
        return trim(rtrim($field, "\0"));
    }

    private static function octal(string $field): int
    {
        $value = self::text($field);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $value) !== 1) {
            return -1;
        }

        return (int) octdec($value);
    }
}
