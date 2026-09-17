<?php

declare(strict_types=1);

namespace App\Exchange;

/**
 * Wynik odczytu pliku do importu: nagłówki w kolejności z pliku i wiersze z numerem linii,
 * żeby podgląd importu wskazywał miejsce błędu tak, jak widzi je osoba edytująca plik.
 */
final class ParsedTable
{
    /**
     * @param list<string>                                           $headers
     * @param list<array{line: int, values: array<string, string>}> $rows
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly string $format,
        public readonly string $encoding = 'UTF-8',
        public readonly ?string $delimiter = null,
    ) {
    }
}
