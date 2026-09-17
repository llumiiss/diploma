<?php

declare(strict_types=1);

namespace App\Exchange;

/**
 * Zapis CSV do eksportu: UTF-8 ze znacznikiem BOM, średnik i CRLF — tak plik otwiera się
 * poprawnie w polskim Excelu. Tekst zaczynający się od znaku formuły dostaje apostrof.
 */
final class CsvWriter
{
    /**
     * @param list<string>                                   $headers
     * @param iterable<list<string|int|float|bool|null>>     $rows
     */
    public static function write(array $headers, iterable $rows, string $delimiter = ';'): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map([Normalizer::class, 'protect'], $headers), $delimiter, '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row), $delimiter, '"', '', "\r\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function cell(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null  => '',
            is_bool($value)  => $value ? '1' : '0',
            is_float($value) => number_format($value, 2, '.', ''),
            is_int($value)   => (string) $value,
            default          => Normalizer::protect($value),
        };
    }
}
