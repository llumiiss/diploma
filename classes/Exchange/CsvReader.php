<?php

declare(strict_types=1);

namespace App\Exchange;

use App\Service\ServiceException;

/**
 * Odczyt CSV do importu. Rozpoznaje separator (średnik, przecinek, tabulator), znacznik BOM
 * i kodowanie Windows-1250, w którym polski Excel zapisuje „CSV (rozdzielany przecinkami)”.
 * Pola w cudzysłowach mogą zawierać separatory i nowe linie (RFC 4180).
 */
final class CsvReader
{
    public const MAX_ROWS = 1000;

    public static function parse(string $content): ParsedTable
    {
        $encoding = 'UTF-8';
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('CP1250', 'UTF-8//IGNORE', $content);
            if ($converted === false) {
                throw ServiceException::validation(['file' => \__('exchange.error.encoding')]);
            }
            $content = $converted;
            $encoding = 'Windows-1250';
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $delimiter = self::detectDelimiter($content);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $headers = null;
        $rows = [];
        $line = 1;
        $position = 0;

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $startLine = $line;
            $next = (int) ftell($handle);
            $line += substr_count($content, "\n", $position, max(0, min($next, strlen($content)) - $position));
            $position = $next;

            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }

            if ($headers === null) {
                $headers = self::uniqueHeaders($cells);
                continue;
            }

            $values = [];
            foreach ($headers as $index => $header) {
                $values[$header] = Normalizer::unprotect($cells[$index] ?? '');
            }
            $rows[] = ['line' => $startLine, 'values' => $values];

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw ServiceException::validation(['file' => \__('exchange.error.too_many_rows', ['max' => (string) self::MAX_ROWS])]);
            }
        }
        fclose($handle);

        if ($headers === null) {
            throw ServiceException::validation(['file' => \__('exchange.error.empty_file')]);
        }

        return new ParsedTable($headers, $rows, 'csv', $encoding, $delimiter);
    }

    private static function detectDelimiter(string $content): string
    {
        $firstLine = (string) strstr($content . "\n", "\n", true);
        $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    /**
     * @param list<string> $cells
     * @return list<string>
     */
    private static function uniqueHeaders(array $cells): array
    {
        $headers = [];
        foreach ($cells as $index => $cell) {
            $name = $cell !== '' ? $cell : '#' . ($index + 1);
            $candidate = $name;
            $suffix = 2;
            while (in_array($candidate, $headers, true)) {
                $candidate = $name . ' (' . $suffix++ . ')';
            }
            $headers[] = $candidate;
        }

        return $headers;
    }
}
