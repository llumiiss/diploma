<?php

declare(strict_types=1);

namespace App\Exchange;

use XMLWriter;

/**
 * Zapis XML do eksportu: lista rekordów (element na rekord, element podrzędny na pole)
 * albo struktura zagnieżdżona — np. karta płatnika z certyfikatami, zadaniami i historią.
 */
final class XmlExporter
{
    /**
     * @param iterable<array<string, mixed>> $records
     * @param array<string, string>          $attributes atrybuty elementu głównego
     */
    public static function records(string $root, string $element, iterable $records, array $attributes = []): string
    {
        $writer = self::start($root, $attributes);
        foreach ($records as $record) {
            self::value($writer, $element, $record, []);
        }

        return self::finish($writer);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $attributes
     * @param array<string, string> $itemNames nazwa listy → nazwa elementu pozycji (np. certificates → certificate)
     */
    public static function tree(string $root, array $data, array $attributes = [], array $itemNames = []): string
    {
        $writer = self::start($root, $attributes);
        foreach ($data as $key => $value) {
            self::value($writer, self::name((string) $key), $value, $itemNames);
        }

        return self::finish($writer);
    }

    public static function name(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);

        return $name === '' || preg_match('/^[A-Za-z_]/', $name) !== 1 ? '_' . $name : $name;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function start(string $root, array $attributes): XMLWriter
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement(self::name($root));
        foreach ($attributes as $name => $value) {
            $writer->writeAttribute(self::name($name), Normalizer::xmlText($value));
        }

        return $writer;
    }

    private static function finish(XMLWriter $writer): string
    {
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @param array<string, string> $itemNames
     */
    private static function value(XMLWriter $writer, string $name, mixed $value, array $itemNames): void
    {
        if (is_array($value)) {
            $writer->startElement($name);
            if (array_is_list($value)) {
                $item = $itemNames[$name] ?? 'item';
                foreach ($value as $child) {
                    self::value($writer, $item, $child, $itemNames);
                }
            } else {
                foreach ($value as $key => $child) {
                    self::value($writer, self::name((string) $key), $child, $itemNames);
                }
            }
            $writer->endElement();

            return;
        }

        $text = match (true) {
            $value === null  => '',
            is_bool($value)  => $value ? '1' : '0',
            is_float($value) => number_format($value, 2, '.', ''),
            is_scalar($value) => (string) $value,
            default          => '',
        };
        $writer->writeElement($name, Normalizer::xmlText($text));
    }
}
